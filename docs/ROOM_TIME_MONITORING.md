# Room-Level Time Monitoring

⚡ **Enhanced in v1.4.0** with unified `config time` commands as part of the comprehensive Room-Level Bot Configuration system.

This document describes the room-level time monitoring feature, which allows per-room configuration of time tracking settings while maintaining backward compatibility with global settings.

## Overview ⚡ Enhanced in v1.4.0

Room-level time monitoring enables different Talk rooms to have their own time tracking configurations, overriding the global defaults when needed. This is particularly useful for organizations with different meeting types that require different time management approaches.

**New in v1.4.0**: Time monitoring is now part of the unified Room-Level Bot Configuration system, offering seamless integration with response modes, agenda limits, auto-behaviors, and custom emojis through consistent `config time` commands.

### Key Features ⚡ Enhanced in v1.4.0

- **Room-specific configuration**: Each Talk room can have its own time monitoring settings
- **Global fallback**: Rooms without specific configuration use global defaults
- **Moderator control**: Room moderators and owners can configure time monitoring for their rooms
- **Backward compatibility**: Existing deployments continue working with global settings
- **Dynamic enabling/disabling**: Time monitoring can be enabled or disabled per room
- **Custom thresholds**: Warning and overtime thresholds can be set per room
- **🎆 New in v1.4.0**: Unified `config time` commands for consistency with other room settings
- **🎆 New in v1.4.0**: Integration with complete room configuration system (`config show`)
- **🎆 New in v1.4.0**: Enhanced language support for background job warnings
- **🎆 New in v1.4.0**: Metadata tracking (who configured, when) for all settings

## Architecture

### Components

1. **RoomConfigService**: Manages room-specific configuration storage and retrieval
2. **AgendaService**: Enhanced with room-aware time monitoring methods
3. **TimeMonitorService**: Updated to use room-specific settings with global fallback
4. **BotInvokeListener**: Routes new time monitoring commands
5. **CommandParser**: Parses new room-level time monitoring commands

### Database Schema

Room configurations are stored as entries in the existing `oc_ab_log_entries` table using the `room_config` entry type:

```sql
-- No new table needed! Uses existing structure:
-- oc_ab_log_entries with type='room_config'
-- Configuration stored as JSON in the details field
```

**Smart Design Benefits:**
- ✨ **Zero schema changes** - Uses existing table structure
- 🚀 **Instant deployment** - No migrations required
- 🔄 **Backward compatible** - Existing setups unaffected
- ⚡ **Efficient queries** - Existing indexes support the pattern

## User Interface ⚡ Enhanced in v1.4.0

### Unified Configuration Commands (v1.4.0+) 🎆 New

Time monitoring is now part of the unified Room-Level Bot Configuration system:

#### Primary Configuration Commands
```
config show                    # Shows complete room configuration including time monitoring
config time help              # Shows time monitoring specific help and examples
config time enable|disable    # Enable/disable time monitoring for this room
config time thresholds X Y    # Set warning thresholds (e.g., 0.8 1.0 for 80% and 100%)
config time overtime X        # Set overtime warning threshold (e.g., 1.2 for 120%)
config time reset             # Reset all time monitoring settings to defaults
```

### Legacy Commands (Deprecated but supported)

These original commands still work but are superseded by the unified `config time` commands:

#### View Configuration
```
time config                   # Use: config show (shows all room config)
```
Shows current time monitoring configuration for the room, indicating whether it's using global defaults or room-specific settings.

#### Enable/Disable Monitoring
```
time enable                   # Use: config time enable
time disable                  # Use: config time disable
```
Enables or disables time monitoring for the specific room.

#### Set Warning Threshold
```
time warning 75               # Use: config time warning 75
```
Sets the first warning threshold to 75% of planned time for the room.

#### Set Overtime Threshold
```
time overtime 110             # Use: config time overtime 110
```
Sets the overtime alert threshold to 110% of planned time for the room.

#### Set Both Thresholds
```
time thresholds 80 120        # Use: config time thresholds 80 120
```
Sets both warning (80%) and overtime (120%) thresholds in one command.

#### Reset to Global Defaults
```
time reset                    # Use: config time reset
```
Removes room-specific configuration, causing the room to fall back to global defaults.

### Permission Requirements

All room-level time monitoring commands require **moderator or owner** permissions in the Talk room.

## Configuration Examples ⚡ Enhanced in v1.4.0

### Complete Room Configuration Display (v1.4.0+)
```
config show
```
Response:
```
**Room Configuration Overview**

**1. Time Monitoring** ⏱️
✅ Time monitoring is currently enabled for this room.
⚠️ Warning thresholds: 80% and 100%
🚨 Overtime warning at 120%
Configured by @alice on 2024-01-15 14:30

**2. Response Mode** 💬
[Additional configuration areas shown...]
```

### Legacy Time-Only Configuration Display
```
time config
```
Response:
```
🕒 Time Monitoring (Global Default)
• Status: ✅ Enabled
• First warning at 80%
• Overtime alert at 120%
• Configured globally by admin
• Time checks run every 5 minutes with Nextcloud's background jobs
```

### Setting Custom Thresholds (v1.4.0+)
```
# New unified command
config time thresholds 70 110
```
Response:
```
✅ Time monitoring thresholds updated: warnings at 70% and 110%
```


## Technical Implementation

### Configuration Storage

Room configurations are stored as JSON-encoded values with the following keys:

- `time_monitoring`: Contains the complete time monitoring configuration
  ```json
  {
    "enabled": true,
    "warning_threshold": 0.8,
    "overtime_threshold": 1.2,
    "configured_by": "user_id",
    "configured_at": 1640995200
  }
  ```

### Service Integration

#### RoomConfigService Methods

```php
// Get room config with global fallback
public function getRoomConfigWithFallback(string $token): array

// Set room-specific configuration
public function setRoomConfig(string $token, string $key, array $value, ?string $userId = null): bool

// Check if room has specific configuration
public function hasRoomConfig(string $token, string $key): bool

// Reset room configuration (remove override)
public function resetRoomConfig(string $token, ?string $key = null): bool

// Get configuration metadata
public function getRoomConfigMetadata(string $token, string $key): ?array
```

#### AgendaService Enhancements

```php
// Get time monitoring status (room-aware)
public function getTimeMonitoringStatus(string $token, string $lang = 'en'): string

// Set time monitoring config for room
public function setTimeMonitoringConfig(array $config, string $token, ?array $actorData = null, string $lang = 'en'): array
```

#### TimeMonitorService Integration

```php
// Check if time monitoring is enabled for specific room
public function isTimeMonitoringEnabledForRoom(string $token): bool

// Get rooms that have monitoring enabled (filters out disabled rooms)
public function getRoomsForTimeMonitoring(): array

// Check time warnings with room-specific thresholds
public function checkTimeWarnings(string $token, string $lang = 'en'): array
```

### Background Job Updates

The `AgendaTimeMonitorJob` now filters rooms based on their individual monitoring settings:

```php
protected function run($argument): void {
    $rooms = $this->timeMonitorService->getRoomsForTimeMonitoring();
    foreach ($rooms as $token) {
        $this->timeMonitorService->checkTimeWarnings($token);
    }
}
```

### Meeting State Management (Enhanced in v1.2.0)

The room-level time monitoring now includes improved meeting state management:

- **Call start behavior**: When a call starts, the first incomplete agenda item is automatically set as current
- **Call end behavior**: When a call ends, all current agenda items are deactivated (no longer "current")
- **State persistence**: Agenda items remain with their completion status, but no item shows as "current" after meeting ends
- **Clean transitions**: Ensures logical meeting flow with proper state transitions

This ensures that time monitoring only applies during active meetings and that agenda state reflects real-world meeting status.

## Migration Strategy

### Backward Compatibility

1. **Existing installations**: Continue working with global settings
2. **Zero database changes**: Uses existing table structure with new entry type
3. **Global settings preserved**: All existing global time monitoring settings remain unchanged
4. **Gradual adoption**: Rooms can opt into room-specific settings as needed

### Migration Path

1. **Phase 1**: Deploy room-level monitoring code (backward compatible)
2. **Phase 2**: Create room configurations as needed using bot commands
3. **Phase 3**: Optionally migrate global settings to room-specific where appropriate

### Data Migration Script

If needed, a migration script can convert global settings to room-specific:

```php
// Example migration for active rooms
$activeRooms = $this->logEntryMapper->findActiveConversationTokens();
$globalConfig = [
    'enabled' => $this->config->getAppValue('agenda_bot', 'time-monitoring-enabled', 'true') === 'true',
    'warning_threshold' => (float) $this->config->getAppValue('agenda_bot', 'warning-threshold-80', '0.8'),
    'overtime_threshold' => (float) $this->config->getAppValue('agenda_bot', 'overtime-warning-threshold', '1.2')
];

foreach ($activeRooms as $token) {
    $this->roomConfigService->setRoomConfig($token, 'time_monitoring', $globalConfig, 'migration');
}
```

## Testing

### Unit Tests

Comprehensive unit tests cover:

1. **RoomConfigService**: CRUD operations, validation, fallback logic
2. **AgendaService**: Room-aware configuration methods and permission checks
3. **TimeMonitorService**: Room-specific warning logic and filtering

### Test Files

- `tests/Unit/Service/RoomConfigServiceTest.php`
- `tests/Unit/Service/AgendaServiceRoomConfigTest.php`
- `tests/Unit/Service/TimeMonitorServiceRoomConfigTest.php`

### Running Tests

```bash
# From Nextcloud root
./vendor/bin/phpunit apps/agenda_bot/tests/

# Or using the provided script
bash apps/agenda_bot/run_tests.sh
```

## Troubleshooting

### Common Issues

1. **Room config not taking effect**
   - Verify moderator permissions
   - Check if background jobs are running
   - Confirm room has active agenda items

2. **Global settings ignored**
   - Check if room has specific configuration overriding global
   - Use `time reset` to remove room overrides

3. **Warnings not sent**
   - Verify time monitoring is enabled for the room
   - Check background job status
   - Ensure agenda item has planned duration

### Debug Commands

```bash
# Check room configuration directly
sudo -u apache /var/www/nextcloud/occ config:app:get agenda_bot room-config-TOKEN

# Verify background job status
sudo -u apache /var/www/nextcloud/occ background:queue:status

# Check application logs
tail -f /var/www/nextcloud/data/nextcloud.log | grep agenda_bot
```

## Security Considerations

1. **Permission Enforcement**: All configuration commands require moderator/owner permissions
2. **Data Validation**: All input values are validated and sanitized
3. **Audit Trail**: Configuration changes are logged with user and timestamp
4. **Isolation**: Room configurations are isolated and cannot affect other rooms

## Performance Impact

- **Minimal overhead**: Room configurations are cached and only loaded when needed
- **Efficient filtering**: Background job filters rooms before processing
- **Database optimization**: Indexed queries for fast configuration retrieval
- **Memory usage**: Configuration objects are lightweight and JSON-encoded

## Future Enhancements

### Planned Features

1. **Room templates**: Predefined configuration templates for common meeting types
2. **Bulk configuration**: Admin tools to configure multiple rooms
3. **Advanced scheduling**: Time-based configuration changes
4. **Integration with Calendar**: Sync with Nextcloud Calendar for time estimates
5. **Analytics**: Room-specific time monitoring reports

### API Extensions

Future versions may include REST API endpoints for programmatic configuration management.

## Implementation Details

### Core Services
- **RoomConfigService**: Complete CRUD service for room-specific configuration management
- **Enhanced AgendaService**: Added room-aware time monitoring methods with permission checks
- **Updated TimeMonitorService**: Implemented room-specific settings with intelligent fallback
- **TimingUtilityService**: Centralized timing calculations and duration parsing for improved code maintainability
- **Database Migration**: Added `oc_ab_room_config` table with proper indexing

### User Interface Enhancements
- **BotInvokeListener**: Extended to route new time monitoring commands
- **CommandParser**: Added regex patterns for parsing room-level time commands
- **Help system**: Updated to include room-level time monitoring commands
- **Permission enforcement**: All room-level commands require moderator/owner permissions

### Background Processing
- **AgendaTimeMonitorJob**: Enhanced to filter rooms based on individual monitoring settings
- **Performance optimization**: Only processes rooms with monitoring enabled
- **Efficient queries**: Uses indexed room configuration lookups

### Localization
- **English translations**: Complete set of translations for all new features
- **German translations**: Full German localization for room-level time monitoring
- **Translation keys**: Added 16+ new translation keys with proper pluralization

## Testing & Quality Assurance

### Unit Tests
- **RoomConfigServiceTest**: 12 comprehensive test methods covering CRUD operations, validation, and fallback logic
- **AgendaServiceRoomConfigTest**: 8 test methods verifying room-aware configuration methods and permission checks
- **TimeMonitorServiceRoomConfigTest**: 8 test methods testing room-specific warning logic and filtering
- **Test runner**: Automated test execution script with coverage reporting

### Code Quality
- **Syntax validation**: All PHP files pass syntax checks
- **Type hints**: Proper PHP 8+ type declarations throughout
- **Error handling**: Comprehensive error handling and validation
- **Performance**: Efficient database queries with proper indexing
- **Code optimization**: Centralized timing logic eliminates duplication and improves maintainability
- **Service architecture**: Clean separation of concerns with specialized utility services

## Key Benefits

### For Users
- **Flexibility**: Different meeting types can have different time management needs
- **Control**: Room moderators can customize monitoring without admin intervention
- **Transparency**: Clear indication of room vs global configuration
- **Simplicity**: Easy-to-use bot commands with helpful feedback

### For Administrators
- **Scalability**: Reduces admin workload by delegating room-level configuration
- **Compatibility**: No disruption to existing deployments
- **Monitoring**: Room configurations include audit trail with user and timestamp
- **Performance**: Efficient background job processing with room filtering

### For Developers
- **Extensibility**: Clean architecture for future room-level features
- **Maintainability**: Well-tested code with comprehensive test coverage  
- **Documentation**: Thorough documentation for future development
- **Standards**: Follows Nextcloud app development best practices

## Summary

Room-level time monitoring provides fine-grained control over time tracking while maintaining the simplicity and reliability of the existing system. The feature is designed for gradual adoption and seamless integration with existing workflows.

This v1.2.0 implementation represents a significant enhancement to Agenda Bot's capabilities while maintaining the app's core principles of simplicity, reliability, and user-focused design.
