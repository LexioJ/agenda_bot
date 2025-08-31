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
use OCP\Security\ISecureRandom;

class BotService {
	public function __construct(
		protected IConfig $config,
		protected IURLGenerator $url,
		protected IEventDispatcher $dispatcher,
		protected ISecureRandom $random,
	) {
	}

	public function installBot(string $backend): void {
		$id = sha1($backend);

		$secretData = $this->config->getAppValue('agenda_bot', 'secret_' . $id);
		if ($secretData) {
			$secretArray = json_decode($secretData, true, 512, JSON_THROW_ON_ERROR);
			$secret = $secretArray['secret'] ?? $this->random->generate(64, ISecureRandom::CHAR_HUMAN_READABLE);
		} else {
			$secret = $this->random->generate(64, ISecureRandom::CHAR_HUMAN_READABLE);
		}
		foreach (Bot::SUPPORTED_LANGUAGES as $lang) {
			$this->installLanguage($secret, $lang);
		}

		$this->config->setAppValue('agenda_bot', 'secret_' . $id, json_encode([
			'id' => $id,
			'secret' => $secret,
			'backend' => $backend,
		], JSON_THROW_ON_ERROR));
	}

	protected function installLanguage(string $secret, string $lang): void {
		$event = new BotInstallEvent(
			'Agenda bot',
			$secret . str_replace('_', '', $lang),
			'nextcloudapp://' . Application::APP_ID . '/' . $lang,
			'Agenda Bot - Specialized bot for managing meeting agendas and tracking agenda items during Talk calls',
			features: 4, // EVENT
		);
		try {
			$this->dispatcher->dispatchTyped($event);
		} catch (\Throwable) {
		}
	}

	public function uninstallBot(string $secret): void {
		foreach (Bot::SUPPORTED_LANGUAGES as $lang) {
			$this->uninstallLanguage($secret, $lang);
		}
	}

	protected function uninstallLanguage(string $secret, string $lang): void {
		$event = new BotUninstallEvent(
			$secret . str_replace('_', '', $lang),
			'nextcloudapp://' . Application::APP_ID . '/' . $lang,
		);
		try {
			$this->dispatcher->dispatchTyped($event);
		} catch (\Throwable) {
		}

		// Also remove legacy secret bots
		$event = new BotUninstallEvent(
			$secret,
			'nextcloudapp://' . Application::APP_ID . '/' . $lang,
		);
		try {
			$this->dispatcher->dispatchTyped($event);
		} catch (\Throwable) {
		}
	}
}
