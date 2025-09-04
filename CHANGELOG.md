# Changelog

All notable changes to the Agenda Bot project will be documented in this file.

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
- **MULTILINGUAL_SUPPORT.md** - Comprehensive implementation guide
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