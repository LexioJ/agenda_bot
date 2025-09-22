<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Service;

use OCA\AgendaBot\AppInfo\Application;
use OCA\AgendaBot\Model\Bot;
use OCA\Talk\Events\BotInstallEvent;
use OCA\Talk\Events\BotUninstallEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Service for managing bot provisioning, configuration, and lifecycle
 * 
 * This service provides idempotent operations for:
 * - Creating and updating bots
 * - Managing bot secrets and configuration
 * - Validating bot state
 * - Supporting migration scenarios
 * 
 * Designed to be used by background jobs and migration processes
 * where reliability and idempotency are critical.
 */
class BotProvisioner {
    private const APP_ID = 'agenda_bot';
    
    // Language display names with formality variants
    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'de' => 'Deutsch: Du',     // Informal German  
        'de_DE' => 'Deutsch: Sie', // Formal German
    ];

    public function __construct(
        private IConfig $config,
        private IURLGenerator $url,
        private IEventDispatcher $dispatcher,
        private ISecureRandom $random,
        private IFactory $l10nFactory,
        private LoggerInterface $logger,
    ) {}

    /**
     * Ensure a bot exists for the specified language
     * Idempotent - safe to call multiple times
     */
    public function ensureBotExists(string $language): bool {
        try {
            if (!$this->isSupportedLanguage($language)) {
                $this->logger->warning('Unsupported language for bot creation', ['language' => $language]);
                return false;
            }

            $secret = $this->ensureSecret();
            $this->installLanguageBot($secret, $language);
            
            $this->logger->info('Bot ensured for language', ['language' => $language]);
            return true;
            
        } catch (\Throwable $e) {
            $this->logger->error('Failed to ensure bot exists', [
                'language' => $language,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Update bot configuration for a language
     * Creates bot if it doesn't exist
     */
    public function updateBotConfiguration(string $language, array $config): bool {
        try {
            if (!$this->ensureBotExists($language)) {
                return false;
            }
            
            // Future: Handle specific configuration updates
            // For now, ensuring existence is sufficient
            
            $this->logger->info('Bot configuration updated', [
                'language' => $language,
                'config_keys' => array_keys($config),
            ]);
            return true;
            
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update bot configuration', [
                'language' => $language,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Migrate bot from one language variant to another
     * Useful for formality migrations (de -> de_DE)
     */
    public function migrateBotLanguage(string $fromLanguage, string $toLanguage): bool {
        try {
            if (!$this->isSupportedLanguage($fromLanguage) || !$this->isSupportedLanguage($toLanguage)) {
                $this->logger->warning('Unsupported language for migration', [
                    'from' => $fromLanguage,
                    'to' => $toLanguage,
                ]);
                return false;
            }

            // Ensure the target bot exists
            if (!$this->ensureBotExists($toLanguage)) {
                return false;
            }

            // Note: We don't automatically remove the source bot as it might still be needed
            // That's a separate decision for the migration logic to make

            $this->logger->info('Bot language migration completed', [
                'from' => $fromLanguage,
                'to' => $toLanguage,
            ]);
            return true;

        } catch (\Throwable $e) {
            $this->logger->error('Failed to migrate bot language', [
                'from' => $fromLanguage,
                'to' => $toLanguage,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Validate that a bot is properly configured
     */
    public function validateBotState(string $language): bool {
        try {
            if (!$this->isSupportedLanguage($language)) {
                return false;
            }

            // Check if secret exists
            $secretData = $this->getSecretData();
            if (!$secretData) {
                $this->logger->warning('Bot secret missing', ['language' => $language]);
                return false;
            }

            // Future: Additional validation (check if bot is actually registered in Talk, etc.)
            
            return true;

        } catch (\Throwable $e) {
            $this->logger->error('Failed to validate bot state', [
                'language' => $language,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove bot for a specific language
     * Use with caution - this is destructive
     */
    public function removeBotLanguage(string $language): bool {
        try {
            if (!$this->isSupportedLanguage($language)) {
                return false;
            }

            $secretData = $this->getSecretData();
            if (!$secretData) {
                $this->logger->info('No secret found, bot likely already removed', ['language' => $language]);
                return true;
            }

            $this->uninstallLanguageBot($secretData['secret'], $language);
            
            $this->logger->info('Bot removed for language', ['language' => $language]);
            return true;

        } catch (\Throwable $e) {
            $this->logger->error('Failed to remove bot', [
                'language' => $language,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get all currently supported languages
     */
    public function getSupportedLanguages(): array {
        return Bot::SUPPORTED_LANGUAGES;
    }

    /**
     * Get display name for a language
     */
    public function getLanguageDisplayName(string $language): string {
        return self::LANGUAGE_NAMES[$language] ?? $language;
    }

    /**
     * Get current bot secret (for debugging/status)
     */
    public function getCurrentSecret(): ?string {
        $secretData = $this->getSecretData();
        return $secretData['secret'] ?? null;
    }

    /**
     * Force regenerate bot secret (use with extreme caution)
     */
    public function regenerateSecret(): bool {
        try {
            $oldSecretData = $this->getSecretData();
            
            // Generate new secret
            $newSecret = $this->random->generate(64, ISecureRandom::CHAR_HUMAN_READABLE);
            
            // Uninstall all old bots if they exist
            if ($oldSecretData) {
                foreach (Bot::SUPPORTED_LANGUAGES as $lang) {
                    $this->uninstallLanguageBot($oldSecretData['secret'], $lang);
                }
            }
            
            // Install all bots with new secret
            foreach (Bot::SUPPORTED_LANGUAGES as $lang) {
                $this->installLanguageBot($newSecret, $lang);
            }
            
            // Update stored secret
            $this->storeSecret($newSecret);
            
            $this->logger->warning('Bot secret regenerated - all existing bot registrations updated');
            return true;

        } catch (\Throwable $e) {
            $this->logger->error('Failed to regenerate secret', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // === Private Helper Methods ===

    /**
     * Check if language is supported
     */
    private function isSupportedLanguage(string $language): bool {
        return in_array($language, Bot::SUPPORTED_LANGUAGES, true);
    }

    /**
     * Ensure secret exists, create if needed
     */
    private function ensureSecret(): string {
        $secretData = $this->getSecretData();
        
        if ($secretData && isset($secretData['secret'])) {
            return $secretData['secret'];
        }
        
        // Generate new secret
        $secret = $this->random->generate(64, ISecureRandom::CHAR_HUMAN_READABLE);
        $this->storeSecret($secret);
        
        return $secret;
    }

    /**
     * Get secret data from config
     */
    private function getSecretData(): ?array {
        $backend = Application::class;
        $id = sha1($backend);
        
        $secretData = $this->config->getAppValue(self::APP_ID, 'secret_' . $id);
        if (!$secretData) {
            return null;
        }
        
        try {
            return json_decode($secretData, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('Invalid secret data JSON', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Store secret data to config
     */
    private function storeSecret(string $secret): void {
        $backend = Application::class;
        $id = sha1($backend);
        
        $secretData = [
            'id' => $id,
            'secret' => $secret,
            'backend' => $backend,
        ];
        
        $this->config->setAppValue(self::APP_ID, 'secret_' . $id, json_encode($secretData, JSON_THROW_ON_ERROR));
    }

    /**
     * Install bot for specific language
     */
    private function installLanguageBot(string $secret, string $language): void {
        $langName = self::LANGUAGE_NAMES[$language] ?? $language;
        
        // Get localized strings
        $l = $this->l10nFactory->get(self::APP_ID, $language);
        
        $event = new BotInstallEvent(
            $l->t('Agenda'),
            $secret . str_replace('_', '', $language),
            'nextcloudapp://' . self::APP_ID . '/' . $language,
            $l->t('Agenda') . ' (' . $langName . ') - ' . $l->t('Specialized bot for managing meeting agendas and tracking agenda items during Talk calls'),
            features: 4 | 8, // EVENT | REACTION
        );
        
        try {
            $this->dispatcher->dispatchTyped($event);
            $this->logger->debug('Bot install event dispatched', ['language' => $language]);
        } catch (\Throwable $e) {
            // Log but don't fail - the event system might not be fully available
            $this->logger->warning('Bot install event failed', [
                'language' => $language,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Uninstall bot for specific language
     */
    private function uninstallLanguageBot(string $secret, string $language): void {
        $event = new BotUninstallEvent(
            $secret . str_replace('_', '', $language),
            'nextcloudapp://' . self::APP_ID . '/' . $language,
        );
        
        try {
            $this->dispatcher->dispatchTyped($event);
            $this->logger->debug('Bot uninstall event dispatched', ['language' => $language]);
        } catch (\Throwable $e) {
            $this->logger->warning('Bot uninstall event failed', [
                'language' => $language,
                'error' => $e->getMessage(),
            ]);
        }

        // Also try to remove legacy secret-only bots (for backwards compatibility)
        $legacyEvent = new BotUninstallEvent(
            $secret,
            'nextcloudapp://' . self::APP_ID . '/' . $language,
        );
        
        try {
            $this->dispatcher->dispatchTyped($legacyEvent);
        } catch (\Throwable) {
            // Ignore legacy cleanup failures
        }
    }
}