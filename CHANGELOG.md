# Changelog

All notable changes to the Agenda Bot project will be documented in this file.

## [1.4.1] - 2025-09-18

### ✨ Added - Agenda Reset Command

#### 🔄 **New `agenda reset` Command**
- **Bulk status reset**: Reset all agenda items to incomplete status with a single command
- **Efficient workflow**: Eliminates need for individual `undone: X` commands for each item
- **Perfect for recurring meetings**: Weekly stand-ups, monthly reviews, and template meetings
- **Time tracking reset**: Automatically resets time monitoring warnings for all items
- **Permission controlled**: Requires moderator/owner permissions for data integrity
- **Smart handling**: Graceful responses for edge cases (no items, already incomplete items)

#### 🚀 **Use Cases**
- **Weekly team meetings**: Quickly reset last week's completed agenda for reuse
- **Monthly reviews**: Reset quarterly agenda items for the next cycle
- **Template meetings**: Efficiently reuse standardized agenda formats
- **Recurring check-ins**: Streamlined workflow for regular meeting patterns

#### 💻 **Technical Implementation**
- **CommandParser**: New `RESET_PATTERN` for command recognition
- **AgendaService**: `resetAllItems()` method with comprehensive error handling
- **BotInvokeListener**: Integrated reset command handler
- **Complete localization**: Full English and German translation support
- **Help integration**: Command documented in moderator help text

#### 🌍 **Localization**
- **English**: Complete translation keys for all reset functionality
- **German**: Full German localization with proper pluralization
- **Error handling**: Localized messages for all edge cases
- **Help text**: Reset command included in contextual help system

#### 📊 **Command Examples**
```bash
agenda reset                    # Reset all agenda items to incomplete
agenda status                   # Verify the reset worked

# Before reset:
# ✅ 1. Project review (completed)
# ✅ 2. Budget discussion (completed)
# ✅ 3. Next steps (completed)

# After reset:
# 📍 1. Project review (15 min)
# 📍 2. Budget discussion (20 min) 
# 📍 3. Next steps (10 min)
```

#### 🎯 **Benefits**
- ✅ **One command replaces many**: `agenda reset` vs. multiple `undone: 1`, `undone: 2`, etc.
- ✅ **Time efficient**: Instant bulk operation for recurring meetings
- ✅ **Preserves structure**: Maintains item order, titles, and durations
- ✅ **Enables reuse**: Perfect for template-based recurring meetings
- ✅ **Smart cleanup**: Automatically resets time monitoring states

## [1.4.0] - 2025-09-14

### 🚀 Added - Room-Level Bot Configuration

Each Talk room can now have its own unique bot configuration across five comprehensive areas:

#### ⚙️ **Unified Configuration System**
- **New `config show` command**: Display complete room configuration overview in a beautiful, organized format
- **Hierarchical configuration**: Room-specific settings override global defaults with intelligent fallback
- **Configuration metadata**: Track who configured what, when, with full audit trail
- **Atomic updates**: Partial configuration updates preserve existing settings
- **Smart reset functionality**: Individual sections can be reset to global defaults

#### 🕙 **Enhanced Time Monitoring Configuration**
- **`config time` command suite**: Unified interface for all time monitoring settings
- **Room-specific thresholds**: Custom warning (10-95%) and overtime (105-300%) percentages
- **`config time enable/disable`**: Toggle monitoring per room without affecting other rooms
- **`config time warning X`**: Set warning threshold (e.g., `config time warning 75`)
- **`config time overtime X`**: Set overtime threshold (e.g., `config time overtime 110`) 
- **`config time thresholds X Y`**: Set both thresholds in one command
- **`config time reset`**: Return to global defaults
- **Backward compatibility**: Existing `time xxx` commands remain fully functional

#### 💬 **Response Behavior Configuration**  
- **`config response` command suite**: Control bot verbosity and notification levels
- **Normal mode**: Full text responses for all commands and operations
- **Minimal mode**: Emoji reactions only, reducing notifications while preserving functionality
- **Smart exceptions**: Help, status, and critical notifications always use text in minimal mode
- **`config response normal/minimal`**: Switch between response modes
- **`config response reset`**: Return to global response defaults

#### 🚧 **Agenda Limits Configuration**
- **`config limits` command suite**: Fine-tune agenda capacity and behavior limits
- **`config limits max-items X`**: Set maximum total agenda items (5-100)
- **`config limits max-bulk X`**: Set maximum bulk operation size (3-50) 
- **`config limits default-duration X`**: Set default item duration in minutes (1-120)
- **`config limits reset`**: Reset all limits to global defaults
- **Validation**: All limits validated with sensible bounds to prevent abuse

#### 🤖 **Auto-behaviors Configuration**
- **`config auto` command suite**: Control automatic bot behaviors during meetings
- **`config auto start-agenda enable/disable`**: Auto-set first agenda item as current on call start
- **`config auto cleanup enable/disable`**: Automatically remove completed items after meetings
- **`config auto summary enable/disable`**: Generate meeting summaries on call end
- **`config auto reset`**: Reset all auto-behaviors to global defaults
- **Meeting flow optimization**: Enhances natural meeting progression

#### 😀 **Custom Emojis Configuration**
- **`config emojis` command suite**: Personalize visual agenda item indicators
- **`config emojis current-item 🎯`**: Set emoji for current agenda item
- **`config emojis completed 🎉`**: Set emoji for completed items
- **`config emojis pending 📋`**: Set emoji for pending items  
- **`config emojis on-time 👌`**: Set emoji for on-time status indicators
- **`config emojis time-warning ⚠️`**: Set emoji for time warnings
- **`config emojis reset`**: Reset to global emoji defaults
- **Validation**: Emoji length limits and fallback to defaults for invalid entries

### 🛠️ Technical Architecture Enhancements

#### **RoomConfigService Expansion**
- **Five new configuration areas**: Complete CRUD operations for each configuration type
- **Intelligent data merging**: Partial updates preserve existing configuration sections
- **Metadata management**: Comprehensive tracking of configuration changes with timestamps
- **Smart cleanup**: Automatic removal of empty configuration entries
- **Method standardization**: Consistent `getXxxConfig()`, `setXxxConfig()`, `resetXxxConfig()` patterns

#### **CommandParser Extensions** 
- **37 new command patterns**: Comprehensive regex patterns for all configuration commands
- **Flexible parameter handling**: Support for optional parameters and multiple command formats
- **Unified parsing**: Consistent command structure across all configuration areas
- **Error resilience**: Graceful handling of malformed commands with helpful feedback

#### **BotInvokeListener Enhancements**
- **5 new command handlers**: `handleConfigShow`, `handleConfigTime`, `handleConfigResponse`, `handleConfigLimits`, `handleConfigAuto`, `handleConfigEmojis`
- **Permission integration**: All configuration commands respect moderator/owner permissions
- **Localization support**: Complete translation integration for all new features
- **User ID extraction**: Robust actor data processing for audit trails

### 📚 **Comprehensive Documentation**
- **Interactive help system**: All new commands integrated into `agenda help` with examples
- **Contextual guidance**: Each configuration area provides usage examples and tips
- **Permission indicators**: Clear indication of required permission levels
- **Visual formatting**: Beautiful markdown formatting with emojis and structured layout

### 🌍 **Complete Localization Support**
- **English translations**: 150+ new translation keys for all configuration features
- **German translations**: Complete German localization for all new functionality  
- **Translation consistency**: Standardized terminology across all configuration areas
- **Dynamic pluralization**: Proper plural forms for counts and statistics
- **Cultural adaptation**: Region-appropriate formatting and language patterns

### 🔒 **Security & Permission Framework**
- **Moderator/Owner restrictions**: All configuration changes require appropriate permissions
- **Permission validation**: Comprehensive checks before any configuration modifications
- **Audit trail**: Complete logging of all configuration changes with user attribution
- **Data validation**: Input sanitization and bounds checking for all configuration values
- **Graceful degradation**: Non-privileged users see appropriate permission denied messages

### ⚡ **Performance & Reliability**
- **Efficient storage**: JSON-based configuration storage with minimal overhead
- **Intelligent caching**: Configuration values cached and loaded on-demand
- **Atomic operations**: Database transactions ensure configuration consistency
- **Graceful fallbacks**: Robust handling of missing or corrupted configuration data
- **Memory optimization**: Lightweight configuration objects with efficient serialization

### 🎯 **User Experience Improvements**
- **Intuitive command structure**: Logical, consistent command hierarchy across all areas
- **Rich visual feedback**: Comprehensive status displays with emojis and formatting
- **Context-aware help**: Relevant examples and usage tips for each configuration area
- **Error recovery**: Clear error messages with suggestions for valid alternatives
- **Progressive disclosure**: Basic commands with optional advanced parameters

### 🔧 **Migration & Compatibility**
- **Zero-disruption upgrade**: Existing installations continue working without changes
- **Backward compatibility**: All existing commands and behaviors preserved
- **Gradual adoption**: Rooms can adopt new configuration features as needed
- **Global defaults**: Unchanged global settings serve as fallback for all rooms
- **Legacy support**: Old `time xxx` commands remain fully functional alongside new `config` suite

### 📊 **Configuration Examples**

**Complete room setup for focused meetings:**
```bash
config time thresholds 70 110        # Tighter time management
config response minimal              # Reduce notification noise  
config limits default-duration 15    # Longer default discussions
config auto start-agenda enable      # Auto-start on call begin
config emojis current-item 🎯        # Focused meeting aesthetic
```

**Quick team standup configuration:**
```bash
config limits max-items 10           # Limit agenda size
config limits default-duration 3     # Short discussion items
config auto cleanup enable           # Auto-remove completed items
config response minimal              # Minimal distractions
```

**Executive boardroom setup:**
```bash
config time warning 85               # Conservative time warnings
config auto summary enable           # Automatic meeting summaries
config limits max-bulk 5             # Controlled agenda imports
config emojis completed ✅           # Professional appearance
```

### 🎉 **Impact Summary**

Version 1.4.0 represents the largest single enhancement in Agenda Bot history, introducing:
- **5 comprehensive configuration areas** with 25+ individual settings
- **37 new commands** for complete customization control
- **200+ new translation keys** across English and German
- **500+ lines** of new service methods and command handlers
- **Complete backward compatibility** ensuring seamless upgrades

This release transforms Agenda Bot from a useful meeting tool into a highly personalized, room-specific assistant that adapts to each team's unique meeting culture and requirements.

## [1.3.6] - 2025-09-12

### 🔧 Enhanced - Agenda Item Management
- **`change: X` command**: New command to edit existing agenda item titles and durations
- **Flexible syntax**: Supports changing title only, duration only, or both with natural language
- **Validation**: Prevents modification of completed items with helpful user guidance
- **Permission control**: Requires moderator/owner permissions for data integrity
- **Localized**: Full support in English and German

### ⚡ Enhanced - Timing System
- **Unified timing logic**: New TimingUtilityService centralizes duration formatting and calculations
- **Improved timing display**: Planned duration now shown immediately at call start
- **Flexible formatting**: Compact single-line for status, multi-line for detailed summaries
- **Consistent formatting**: Unified duration display across all features
- **Better user experience**: Enhanced visual formatting and alignment in timing summaries

### 🛠️ Technical Improvements
- **Code organization**: Eliminated duplicate timing code across multiple services
- **CommandParser**: Added regex patterns for modification command parsing
- **Service integration**: TimingUtilityService properly integrated across components
- **Translation updates**: New keys for modification features and separated timing labels
- **Performance**: Reduced redundant calculations through shared utility methods
- **Bug fixes**: Fixed `time reset` command error and threshold preservation in time monitoring

### 📚 New Commands
```bash
change: 2 New title (25 min)    # Change both title and duration
change: 3 Updated review         # Change title only  
change: 1 (45 min)               # Change duration only
```

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