<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Migration\Tasks;

/**
 * Interface for migration tasks
 */
interface IMigrationTask {
    
    /**
     * Get human-readable name of the migration task
     */
    public function getName(): string;
    
    /**
     * Get description of what this migration does
     */
    public function getDescription(): string;
    
    /**
     * Execute the migration task
     * 
     * @return bool True if migration succeeded, false otherwise
     */
    public function execute(): bool;
    
    /**
     * Get the version when this migration is required
     */
    public function getRequiredVersion(): string;
}