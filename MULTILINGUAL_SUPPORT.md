# Multi-language Support Implementation

## Overview
This document describes the implementation of multi-language support for the Agenda Bot, following Nextcloud's l10n standard.

## Implementation Details

### 1. Services Updated
The following services have been updated to support l10n:

#### AgendaService
- Added `IFactory $l10nFactory` dependency injection
- Updated key methods to accept `string $lang = 'en'` parameter:
  - `addAgendaItem()`
  - `getAgendaStatus()`
  - `getAgendaHelp()`
  - `setCurrentAgendaItem()`
  - `completeCurrentAgendaItem()`
  - `completeAgendaItem()`
  - `reopenAgendaItem()`
  - `clearAgenda()`
  - `removeCompletedItems()`
  - `formatDurationDisplay()`

#### PermissionService
- Added `IFactory $l10nFactory` dependency injection
- Updated `getPermissionDeniedMessage()` to accept language parameter
- All permission-related messages now use l10n

#### SummaryService
- Added `IFactory $l10nFactory` dependency injection
- Updated `generateAgendaSummary()` to support language parameter
- All summary messages now use l10n

#### TimeMonitorService
- Added `IFactory $l10nFactory` dependency injection
- Updated `generateWarningMessage()` to support language parameter
- All time monitoring alerts now use l10n
- **Note**: Currently uses 'en' fallback for background job warnings (room language detection limitation)

#### BotInvokeListener
- Updated to pass language parameter to all service method calls
- Language detection from event data
- Welcome messages now use l10n

### 2. Translation Files Created

#### English (en.json)
- Complete translation file with 72+ strings
- Includes all user-facing messages
- Proper pluralization rules

#### German (de.json)
- Example translation file with German translations
- Demonstrates how additional languages can be added

### 3. Key Features

#### Language Detection
- Language is extracted from bot invoke events
- Falls back to 'en' (English) as default
- Passed through the entire call chain

#### Translation Structure
```json
{
    "translations": {
        "key": "translated value"
    },
    "pluralForm": "nplurals=2; plural=(n != 1);"
}
```

#### Usage Pattern
```php
$l = $this->l10nFactory->get(Application::APP_ID, $lang);
$message = $l->t('Translatable message');
```

### 4. Translated Message Categories

#### Status Messages
- Agenda status displays
- Item completion status
- Current item indicators

#### Error Messages
- Permission denied messages
- Item not found errors
- Invalid operation warnings

#### Action Confirmations
- Item added confirmations
- Completion notifications
- Reordering results

#### Help Content
- Command descriptions
- Usage instructions
- Feature explanations

#### Time Monitoring
- Time check alerts
- Overtime warnings
- Duration displays

#### Summary Generation
- Meeting summaries
- Progress reports
- Cleanup suggestions

### 5. Files Modified

#### Core Services
- `/lib/Service/AgendaService.php`
- `/lib/Service/PermissionService.php`
- `/lib/Service/SummaryService.php`
- `/lib/Service/TimeMonitorService.php`

#### Event Handling
- `/lib/Listener/BotInvokeListener.php`

#### Translation Files
- `/l10n/en.json` (English)
- `/l10n/de.json` (German example)

### 6. Testing
- Created `test_l10n.php` for basic validation
- Translation files validated for JSON structure
- All services maintain backward compatibility

## Usage Examples

### Adding New Languages
1. Create new translation file: `/l10n/{language_code}.json`
2. Copy structure from `en.json`
3. Translate all values
4. Update pluralization rules if needed

### Adding New Translatable Strings
1. Add string to all existing translation files
2. Use `$l->t('Your string')` in PHP code
3. Pass language parameter through method calls

## Bot Registration

### Multi-Language Bot Instances
The Agenda Bot follows the same pattern as the Call Summary bot by registering **separate bot instances** for each supported language:

- **English Bot**: `Agenda bot (English)` 
- **German Bot**: `Agenda bot (Deutsch)`

Each language bot:
- Has its own unique secret/identifier
- Displays localized name and description
- Responds in the appropriate language
- Is registered via `BotInstallEvent` with language-specific URLs

### Current Language Support
- **English** (`en`) - Complete
- **German** (`de`) - Complete

### Bot Registration Process
1. `BotService::installBot()` iterates through `Bot::SUPPORTED_LANGUAGES`
2. `installLanguage()` creates localized `BotInstallEvent` for each language
3. Each bot gets unique identifier: `{secret}{lang}` (e.g., `abc123en`, `abc123de`)
4. Bot URL includes language: `nextcloudapp://agenda_bot/{lang}`

## Current Limitations

### Background Job Language Detection
- **TimeMonitorService**: Background jobs cannot access bot URL language context
- Currently uses 'en' (English) fallback for time monitoring warnings
- Room-based language detection methods not available in current Talk API
- **Impact**: Time warnings always sent in English, regardless of room language

### Language Context Availability
- Interactive bot commands: ✅ Full language support (via bot URL)
- Background time monitoring: ⚠️ English only (no language context available)
- Event-driven responses: ✅ Full language support (via event data)

## Future Enhancements
- **Room language detection**: Implement proper room-based language detection when API becomes available
- User preference-based language selection
- Room-specific language settings
- Dynamic language switching
- Additional language support (French, Spanish, Italian, etc.)
- Background job language context preservation

## Compatibility
- Maintains full backward compatibility
- Default English fallback for all messages
- Graceful handling of missing translations