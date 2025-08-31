<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20250829000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('ab_log_entries')) {
			$table = $schema->createTable('ab_log_entries');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
			]);

			$table->addColumn('server', Types::STRING, [
				'notnull' => true,
				'length' => 512,
			]);
			$table->addColumn('token', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			$table->addColumn('type', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('details', Types::TEXT, [
				'notnull' => false,
			]);

			// Additional columns for agenda-specific functionality
			$table->addColumn('order_position', Types::INTEGER, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('duration_minutes', Types::INTEGER, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('start_time', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('parent_id', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('conflict_resolved', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('warning_sent', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('is_completed', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('completed_at', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['server', 'token'], 'ab_log_entry_origin');
			$table->addIndex(['token', 'type'], 'ab_log_entry_type');
			$table->addIndex(['token', 'type', 'is_completed'], 'ab_completion_status');
			$table->addIndex(['token', 'order_position'], 'ab_agenda_order');
			return $schema;
		}
		return null;
	}
}
