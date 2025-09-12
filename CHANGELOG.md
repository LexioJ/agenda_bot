# Changelog

All notable changes to the Agenda Bot project will be documented in this file.

## [1.3.5] - 2025-09-12

### 🔧 Enhanced - Bot Identity & User Experience
- **Unified bot naming**: Simplified bot names from "Agenda bot" to "Agenda" (English) and "Tagesordnung" (German)
- **Clean display names**: Eliminated redundant naming like "Agenda bot (Bot)-bot" that occurred due to Nextcloud's automatic bot suffixing
- **Professional appearance**: Bot now displays as "Agenda (Bot)" or "Tagesordnung (Bot)" across all languages
- **Consistent branding**: Unified naming throughout bot registration, welcome messages, and all user interactions

### 🛠️ Fixed - Welcome Message Experience
- **Personal introduction**: Updated welcome messages to be more engaging and personal
- **Friendly assistant approach**: Bot now introduces itself as "Hi there! I'm your agenda assistant" instead of formal "Welcome to Agenda Bot!"
- **Clean bot identification**: Fixed welcome message sender to display proper bot name without redundant suffixes
- **Localized greetings**: German welcome messages now use "Hallo! Ich bin Ihr Tagesordnungs-Assistent" for consistency

### ⚡ Added - Command Flexibility
- **Enhanced `next:` command**: Added support for `next:` without item number to match `done:` behavior
- **Consistent workflow**: Both `next:` and `done:` commands now complete current item and auto-advance when used without numbers
- **Intuitive shortcuts**: Users can choose whichever command feels more natural for their workflow
- **Backward compatibility**: All existing `next: X` functionality preserved for setting specific agenda items as current

### 🌍 Enhanced - Multi-Language Support
- **Translation consistency**: Updated all translation files to use simplified bot names
- **Welcome message localization**: Improved German translations for more natural, professional tone
- **Command documentation**: Updated help and documentation to reflect new command flexibility
- **Language-aware naming**: Bot registration respects language preferences with appropriate names

### 🛠️ Technical Improvements
- **CommandParser**: Enhanced `NEXT_PATTERN` regex to support optional item numbers
- **AgendaService**: Updated `setCurrentAgendaItem()` method to handle null position parameter
- **BotService**: Simplified bot registration to use clean base names
- **Translation updates**: Comprehensive updates to English and German language files
- **Code consistency**: Improved method signatures and parameter handling across services

## [1.3.4] - 2025-09-08

### 🚀 Added - Silent Call Detection
- **Silent call detection**: Bot now intelligently detects when calls are started silently and responds appropriately
- **Notification-aware responses**: Agenda status messages are sent silently for silent calls to avoid unwanted notifications
- **Enhanced call handling**: Preserves the intent of silent calls while still providing helpful agenda information

### 🔧 Enhanced - User Experience
- **Improved user experience**: Silent calls won't trigger notification-heavy agenda messages
- **Better meeting flow**: Respects user's choice to start calls quietly while maintaining functionality
- **Smart notification management**: Bot messages match the notification behavior of the call that triggered them

### 🛠️ Technical Improvements
- **System message parsing**: Enhanced event processing to detect silent call metadata in BotInvokeListener
- **Fallback detection**: Multiple detection methods ensure reliable silent call identification
- **Debug logging**: Added comprehensive logging for call event analysis and troubleshooting
- **Enhanced `isCallStartedSilently()` method**: Analyzes ActivityPub system message events for silent call indicators
- **Smart response behavior**: Uses `addAnswer($message, $isCallSilent)` to match notification behavior to call type

### 📚 Documentation
- **Technical documentation**: Added comprehensive `docs/SILENT_CALL_HANDLING.md` with implementation details
- **Usage examples**: Code snippets and testing guidelines for the new feature
- **Behavior explanation**: Clear documentation of silent vs. regular call handling

## [1.3.3] - 2025-09-08

### 🚀 Added - Welcome Message on Activation
- Bot now automatically posts a welcome message when activated in a room (new or existing)
- Welcome includes usage guidance and example commands
- Note included that the message can be deleted if desired

### 🔧 Enhanced - Bot Identity
- Welcome message now appears from "Agenda Bot (Bot)" instead of raw bot actor IDs
- Localization-aware: respects room language for bot name and message content

### 🛠️ Fixed - Posting Logic
- Resolved issue where Join events didn’t result in posted messages due to lack of IComment
- Implemented direct ChatManager send for activation events to ensure reliability
- Added detailed logging for activation and message posting

## [1.3.2] - 2025-09-07

### 🔧 Enhanced - User Interface
- **Enhanced agenda summary header**: Increased agenda summary message header from H3 (`###`) to H2 (`##`) for better visibility
- **Visual prominence improvement**: Meeting summaries now appear with larger, more prominent headers
- **Better attention-grabbing**: Makes agenda summaries easier to spot in chat conversations

### 🛠️ Fixed - Reaction-Based Cleanup
- **Reaction-based cleanup false positives**: Fixed overly broad cleanup detection that incorrectly triggered on unrelated messages
- **Removed problematic fallback logic**: Eliminated fallback that caused cleanup to trigger when reacting with 👍 to any message containing "agenda" or "summary"
- **Improved detection precision**: Cleanup via reactions now only works on confirmed agenda summary messages (proper bot-generated summaries)
- **Enhanced safety**: Prevents false positive cleanups while preserving legitimate summary cleanup functionality
- **Better logging**: Added detailed debug logging to track cleanup decision-making process

### 🎯 Technical Details
- **Reaction handler refinement**: Removed broad keyword-based fallback in `handleReactionEvent()` method
- **Summary message detection**: Now relies on explicit summary message ID tracking and bot emoji detection
- **Backward compatibility**: Maintains all existing cleanup functionality for legitimate use cases

## [1.3.1] - 2025-09-06

### 🛠️ Fixed - Time Monitoring Bot Name
- **TimeMonitorService bot name localization**: Fixed time monitoring messages to use proper localized bot name
  - Time warnings now appear from "Agenda bot" instead of "agenda_bot-bot"
  - Consistent with BotService naming convention using `$l->t('Agenda bot')`
  - Supports all configured languages (English, German) with proper translation keys
  - Maintains consistency across all bot interactions and user-facing messages

### 🔧 Enhanced - Multi-Language Consistency
- **Localized sender identification**: Time monitor warnings now respect room language settings
- **Translation alignment**: Bot name display now matches the registered bot names in Talk
- **Future-ready**: Framework supports additional languages like French ("ordre du jour")

## [1.3.0] - 2025-09-05

### 🚀 Added - Bulk Agenda Import
- **Bulk agenda creation**: Create multiple agenda items with a single message using structured list format
- **Flexible bullet format**: Support for both `-` and `*` bullet point markers in bulk import
- **Copy-paste workflow**: Perfect for importing agendas from calendar invitations, meeting templates, or external tools
- **Safety limits**: Maximum 20 items per bulk operation to prevent abuse
- **Error resilience**: Graceful handling of invalid items while processing valid ones
- **Batch confirmation**: Clear summary of all successfully added items with positions and durations

### 🔧 Enhanced - Core Services
- **AgendaService**: New `parseBulkAgendaItems()` and `addBulkAgendaItems()` methods with atomic transaction support
- **BotInvokeListener**: Enhanced message processing with bulk format detection (takes priority over single items)
- **Permission system**: Bulk operations respect existing permission model (moderators, owners, regular users)
- **Position management**: Intelligent sequential positioning with conflict resolution
- **Time parsing**: Full compatibility with existing time formats `(5 min)`, `(1h)`, `(30m)`, etc.

### 💬 Enhanced - User Experience
- **Help system**: Updated `agenda help` command with bulk format examples and usage instructions
- **Localization**: Complete translation support in English and German for all bulk operation messages
- **Error feedback**: Clear error messages for invalid formats, exceeded limits, or processing failures
- **Progress tracking**: Detailed confirmation showing exactly what was created

### 🎯 Usage Examples
```
agenda:
- Welcome & introductions (5 min)
- Project status review (20 min)
- Budget discussion (15 min)
- Next steps planning (10 min)
- Closing remarks
```

### 📚 Documentation
- **README.md**: New "Bulk Agenda Creation" section with examples and feature overview
- **Help integration**: Bulk format examples embedded in bot help system
- **Key features**: Updated feature list to highlight bulk import capability

## [1.2.0] - 2025-09-05

### ⏰ Added - Room-Level Time Monitoring
- **Room-specific time monitoring configuration**: Each Talk room can now have its own time monitoring settings
- **New bot commands**: `time config`, `time enable/disable`, `time warning X`, `time overtime X`, `time thresholds X Y`, `time reset`
- **Global fallback system**: Rooms without specific configuration automatically use global defaults
- **RoomConfigService**: New service for managing room-specific configuration storage
- **Enhanced AgendaService**: Added room-aware time monitoring methods with permission checks
- **Updated TimeMonitorService**: Now uses room-specific settings with intelligent fallback logic
- **Database migration**: Creates `oc_ab_room_config` table for storing room configurations
- **Comprehensive unit tests**: Full test coverage for room-level configuration features
- **Backward compatibility**: Existing deployments continue working without changes

### 👍 Added - Emoji Reaction Support
- **Agenda cleanup via emoji reactions**: Users can now clean up completed agenda items using emoji reactions (👍, ✅, 🧹)
- **Enhanced bot registration**: Bots now register with EVENT (4) and REACTION (8) features enabled (bitwise OR 12)
- **Reaction event handling**: Added comprehensive reaction event processing in BotInvokeListener
- **Permission-based reactions**: Only moderators and owners can use emoji reactions for agenda cleanup
- **Multi-language reaction support**: Reaction processing includes proper language detection and localized responses
- **Fallback reaction detection**: Smart detection of agenda summary messages for reaction processing

### 🔧 Enhanced - Core Features
- **BotInvokeListener**: Extended to handle new room-level time monitoring commands and reaction events
- **CommandParser**: Added regex patterns for parsing room-level time commands
- **Background job filtering**: `AgendaTimeMonitorJob` now filters rooms based on individual monitoring settings
- **Permission system**: All room-level time commands require moderator/owner permissions
- **Localization**: Added German and English translations for all new features
- **Meeting state management**: Current agenda items are now properly cleared when calls end
- **Updated cleanup instructions**: Agenda summaries now show both text command and emoji reaction options

### 🛠️ Fixed - Critical Bugs
- **Reaction permission handling**: Fixed permission checks failing due to bot actor in reaction events
- **Language localization in reactions**: Fixed English error messages appearing in German rooms during reactions
- **Call end behavior**: Current agenda items are now properly deactivated when meetings end
- **Bot feature registration**: Corrected bot registration to include reaction handling capabilities
- **Event actor detection**: Improved handling of reaction events where actor data contains bot info instead of reacting user

### 📚 Documentation
- **docs/ROOM_TIME_MONITORING.md**: Comprehensive guide to room-level time monitoring features
- **Updated README.md**: Added room-level time monitoring command reference and emoji reaction documentation
- **Enhanced help system**: Bot help now includes room-level time monitoring commands
- **Migration guide**: Instructions for gradual adoption of room-specific settings
- **Updated localization files**: Enhanced cleanup instructions to mention emoji reactions

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.3] - 2025-09-04

### Fixed
- **Time Monitor Service**: Critical fix for background job failures
  - Resolved `Call to undefined method OCA\Talk\Room::getLanguage()` error
  - TimeMonitorService now uses simplified language fallback to 'en' for time warnings
  - Background job `AgendaTimeMonitorJob` now executes successfully without errors
  - Time monitoring warnings are properly sent for agenda items exceeding time limits
- **UI Improvements**: Enhanced agenda item display formatting
  - Current agenda item now displays with clean code block formatting
  - Replaced pending emoji ⏸️ with more intuitive 📍 pin emoji across all interfaces
  - Updated translation files, documentation, and service messages for consistency

### Technical
- Added TODO comment for future proper room language detection implementation
- Improved error handling and logging in TimeMonitorService
- Enhanced visual clarity of current agenda item status display

## [1.1.2] - 2025-09-02

### Fixed
- **Translation System**: Fixed string formatting in all core services
  - Switched from sprintf() to proper l10n array parameters
  - Fixed guest name localization in BotInvokeListener
  - Improved language detection from room settings
  - Added proper language parameter passing through service calls
- **BotInvokeListener**: Corrected invalid call signature to `getCurrentAgendaItem()`
- **Parser**: Removed dead/unused code (obsolete help builder and time debug pattern)

### Changed
- **Interval Display**: Normalized all interval-related messages to minutes (was mixed seconds/minutes before)
- **Status Output**: Duration units in agenda status now consistently respect the selected language
- **Summary Reactions**: Summary detection is now language-agnostic via a stable internal marker
- **Translations**: Improved coverage in `en.json` and `de.json` (time monitoring UI, config messages, permission phrases, and formatting strings); aligned help examples

### Performance
- Reduced database queries by including `start_time` in agenda items and using it to compute actual durations in status and summaries

## [1.1.1] - 2025-01-XX

### Fixed
- **Bot Permission Handling**: Fixed permission check failures when bot actors access help commands
  - Bot actors (type: "Application" with "bots/" prefix) now have full permissions by default
  - Resolves "Missing talkParticipantType in actor data" errors in logs
  - Fixes bot registration display showing "__language_name__" placeholder
  - English bot now displays as "Agenda bot (English)", German as "Agenda bot (Deutsch)"

### Technical
- Updated `PermissionService` methods to handle bot actors properly
- Added explicit language name mapping in `BotService`
- Improved error handling and logging for permission checks

## [1.1.0] - 2025-01-XX

### 🌍 Added - Multi-Language Support
- **Complete internationalization (i18n) implementation** following Nextcloud l10n standards
- **Separate bot instances for each language** - users can now choose their preferred language bot
- **English (en)** - Complete translation with 74+ localized strings
- **German (de)** - Complete translation with 45+ localized strings
- **Language detection** from bot events with automatic fallback to English
- **Localized bot registration** - bot names and descriptions appear in user's language

### 🔧 Enhanced - Core Services
- **AgendaService**: All user-facing messages now support localization
  - Agenda status displays, item management, help content
  - Time duration formatting with locale-specific units
  - Error messages and action confirmations
- **PermissionService**: Permission denied messages localized
- **SummaryService**: Meeting summaries and reports in user's language
- **TimeMonitorService**: Time monitoring alerts and warnings localized
- **BotInvokeListener**: Welcome messages and all bot responses localized

### 📝 Improved - User Experience
- **Help command** (`agenda help`) now displays in user's preferred language
- **Status messages** for agenda operations localized
- **Time monitoring alerts** respect language preferences
- **Meeting summaries** generated in appropriate language
- **Error messages** and confirmations translated

### 🏗️ Technical Implementation
- **Dependency injection** of `IFactory $l10nFactory` across all services
- **Language parameter passing** through entire service call chain
- **Translation file structure** following Nextcloud standards
- **Backward compatibility** maintained - existing functionality unchanged
- **Graceful fallbacks** to English for missing translations

### 📚 Documentation
- **docs/MULTILINGUAL_SUPPORT.md** - Comprehensive implementation guide
- **Translation examples** and usage patterns
- **Bot registration process** documentation
- **Future enhancement roadmap**

### 🔄 Bot Registration Changes
- **Multi-language bot instances**: English and German bots register separately
- **Unique identifiers** per language bot (e.g., `{secret}en`, `{secret}de`)
- **Language-specific URLs**: `nextcloudapp://agenda_bot/{lang}`
- **Localized bot descriptions** in Talk bot selection

### 🛠️ Developer Experience
- **Consistent l10n patterns** across all services
- **Easy language addition** - framework ready for new languages
- **Translation validation** tools and examples
- **Comprehensive code documentation**

---

## [1.0.0] - 2025-01-XX

### 🎉 Initial Release
- **Core agenda management** functionality
- **Time tracking and monitoring** features
- **Permission-based access control**
- **Meeting summaries and analytics**
- **Integration with Nextcloud Talk**
- **Background job processing**
- **Comprehensive command system**

### ✨ Key Features
- Add, manage, and track agenda items during meetings
- Real-time time monitoring with configurable thresholds
- Role-based permissions (moderators, participants, guests)
- Automatic meeting summaries and progress tracking
- Flexible agenda reordering and completion tracking
- Integration with Nextcloud Talk conversations

### 🏗️ Technical Foundation
- **PHP 7.4+** compatibility
- **Nextcloud 31-32** support
- **Database integration** with indexed schema
- **Event-driven architecture** with Talk integration
- **Background job processing** for time monitoring
- **Comprehensive logging** and error handling