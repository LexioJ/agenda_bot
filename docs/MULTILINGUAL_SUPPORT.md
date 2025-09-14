# Multi-language Support Implementation

## Overview
This document describes the implementation of multi-language support for the Agenda Bot, following Nextcloud's l10n standard. Enhanced in v1.4.0 with comprehensive Room-Level Bot Configuration localization.

## v1.4.0 Configuration Localization Features 🎆 New

### Room-Level Configuration Translation
All 5 configuration areas are fully localized:

#### 1. Time Monitoring Configuration
```php
// Examples of translated configuration messages
$this->l->t('Time monitoring is currently enabled for this room.')
$this->l->t('Warning thresholds: {percentage1}% and {percentage2}%')
$this->l->t('Overtime warning at {threshold}%')
```

#### 2. Response Mode Configuration
```php
// Response mode descriptions
$this->l->t('Response mode: Normal (shows confirmations and status)')
$this->l->t('Response mode: Minimal (reduced output for less distraction)')
```

#### 3. Agenda Limits Configuration
```php
// Limit configuration messages
$this->l->t('Maximum agenda items: {limit}')
$this->l->t('No agenda item limit set')
```

#### 4. Auto-Behavior Configuration
```php
// Auto-behavior descriptions
$this->l->t('Auto-summary: {status} (generates meeting summary when agenda completes)')
$this->l->t('Auto-cleanup: {status} (suggests cleanup after meeting ends)')
```

#### 5. Custom Emojis Configuration
```php
// Emoji configuration feedback
$this->l->t('Custom pending emoji: {emoji}')
$this->l->t('Custom completed emoji: {emoji}')
$this->l->t('Using default emojis: ⏳ (pending) and ✅ (completed)')
```

### Enhanced Help System
Contextual help is now fully localized:

#### Role-Based Help Content
```php
// Different help for moderators vs users
if ($isModerator) {
    $help[] = $this->l->t('**Moderator Commands:**');
    $help[] = $this->l->t('• `config show` - Show complete room configuration overview');
}
```

#### Configuration Area Help
```php
// Each configuration area has dedicated help
$this->l->t('**Time Monitoring:** Configure meeting duration tracking and warnings')
$this->l->t('**Response Mode:** Control bot verbosity (normal/minimal)')
$this->l->t('**Agenda Limits:** Set maximum number of agenda items')
```

### Translation Key Organization
New translation keys follow a structured naming convention:

#### Configuration Display Keys
- `config_show_*` - Configuration overview display
- `config_area_*` - Configuration area names and descriptions
- `config_setting_*` - Individual setting descriptions
- `config_metadata_*` - "Configured by" and timestamp information

#### Configuration Command Keys
- `config_cmd_*` - Command-specific messages
- `config_validation_*` - Input validation messages
- `config_success_*` - Success confirmation messages
- `config_error_*` - Error and warning messages

#### Help System Keys
- `help_*` - General help content
- `help_config_*` - Configuration-specific help
- `help_role_*` - Role-specific help content

## Implementation Details

### 1. Language Detection
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

#### English (en.json) ⚡ Enhanced in v1.4.0
- Complete translation file with 200+ strings
- Includes all user-facing messages and configuration options
- All 5 configuration areas fully translated
- Proper pluralization rules
- Comprehensive error handling messages

#### German (de.json) ⚡ Enhanced in v1.4.0
- Complete German translation with 200+ strings
- Full localization for all Room-Level Bot Configuration features
- Cultural adaptations for German-speaking users
- Professional terminology for business environments

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

### 4. Translated Message Categories ⚡ Enhanced in v1.4.0

#### Status Messages
- Agenda status displays
- Item completion status
- Current item indicators
- Configuration overview displays

#### Error Messages
- Permission denied messages
- Item not found errors
- Invalid operation warnings
- Configuration validation errors

#### Action Confirmations
- Item added confirmations
- Completion notifications
- Reordering results
- Configuration update confirmations

#### Help Content ⚡ New in v1.4.0
- Command descriptions with examples
- Usage instructions for all 5 configuration areas
- Feature explanations with contextual help
- Role-based help content (moderator vs. user)

#### Room Configuration 🎆 New in v1.4.0
- Complete `config show` output localization
- All configuration area descriptions
- Setting names and value descriptions
- Configuration metadata (who configured, when)
- Help text for each configuration area

#### Time Monitoring
- Time check alerts
- Overtime warnings
- Duration displays
- Threshold configuration messages

#### Response & Behavior Configuration 🎆 New in v1.4.0
- Response mode descriptions (normal/minimal)
- Auto-behavior setting descriptions
- Limit configuration messages
- Emoji customization feedback

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

### Current Language Support ⚡ Enhanced in v1.4.0
- **English** (`en`) - Complete with 200+ translation keys including all configuration areas
- **German** (`de`) - Complete with 200+ translation keys including all configuration areas
- **Room Configuration**: All 5 configuration areas fully translated in both languages
- **Contextual Help**: Configuration-specific help and examples localized

### Bot Registration Process
1. `BotService::installBot()` iterates through `Bot::SUPPORTED_LANGUAGES`
2. `installLanguage()` creates localized `BotInstallEvent` for each language
3. Each bot gets unique identifier: `{secret}{lang}` (e.g., `abc123en`, `abc123de`)
4. Bot URL includes language: `nextcloudapp://agenda_bot/{lang}`

## Current Limitations ⚡ Improved in v1.4.0

### Background Job Language Detection ⚡ Enhanced
- **TimeMonitorService**: Now uses room language storage for background job localization
- **RoomConfigService**: Stores room language preferences for background job use
- Room language automatically detected and stored from bot interactions
- **Impact**: Time warnings now sent in appropriate room language
- **Fallback**: English used only when room language cannot be determined

### Language Context Availability ⚡ Enhanced in v1.4.0
- Interactive bot commands: ✅ Full language support (via bot URL)
- Background time monitoring: ✅ Room language support (via stored room preferences)
- Event-driven responses: ✅ Full language support (via event data)
- Configuration commands: ✅ Complete localization for all 5 areas
- Help system: ✅ Contextual, role-based help in user's language

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