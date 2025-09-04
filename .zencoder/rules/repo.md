---
description: Repository Information Overview
alwaysApply: true
---

# Agenda Bot Information

## Summary
Agenda Bot is a Nextcloud app that transforms meeting management in Nextcloud Talk with intelligent agenda management, real-time time tracking, and automated progress monitoring. It provides features like flexible agenda creation, time monitoring, permission-based access control, and meeting analytics.

## Structure
- **appinfo/**: Contains app metadata and configuration (info.xml)
- **lib/**: Core application code organized in namespaces
  - **AppInfo/**: Main application bootstrap
  - **Model/**: Database entities and mappers
  - **Service/**: Core business logic services
  - **BackgroundJob/**: Background processing tasks
  - **Listener/**: Event listeners for Talk integration
  - **Migration/**: Database schema and installation scripts
- **docs/**: Documentation and screenshots

## Language & Runtime
**Language**: PHP
**Version**: PHP 7.4+ (based on strict_types declaration)
**Framework**: Nextcloud App Framework
**Namespace**: OCA\AgendaBot

## Dependencies
**Nextcloud Dependencies**:
- Nextcloud: v31-32
- Nextcloud Talk app (OCA\Talk)

**PHP Dependencies**:
- No external PHP dependencies (composer.json not found)
- Uses Nextcloud's native APIs and services

## Build & Installation
```bash
# Clone the repository
git clone https://github.com/lexioj/agenda_bot.git

# Copy to Nextcloud apps directory
cp -r agenda_bot /path/to/nextcloud/apps/

# Set proper ownership
chown -R www-data:www-data /path/to/nextcloud/apps/agenda_bot/

# Enable the app
sudo -u www-data php /path/to/nextcloud/occ app:enable agenda_bot
```

## Database
**Schema**: Uses `oc_ab_log_entries` table with indexed fields
**Key Fields**:
- Core: id, server, token, type, details
- Agenda-specific: order_position, duration_minutes, start_time, parent_id
- Status: conflict_resolved, warning_sent, is_completed, completed_at

## Key Components
**Core Services**:
- **AgendaService**: Manages agenda items lifecycle
- **BotService**: Handles bot registration and management
- **CommandParser**: Parses natural language commands
- **PermissionService**: Role-based access control
- **TimeMonitorService**: Time tracking and warnings
- **SummaryService**: Meeting analytics and summaries

**Integration**:
- **BotInvokeListener**: Responds to Talk events
- **AgendaTimeMonitorJob**: Background monitoring service

## Testing
Testing is primarily manual through Nextcloud Talk rooms:
- PHP syntax validation
- Database migration testing
- Permission testing across different user roles

## License
AGPL-3.0-or-later