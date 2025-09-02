# Changelog

All notable changes to the Agenda Bot project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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