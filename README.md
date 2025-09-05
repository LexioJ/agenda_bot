# Agenda Bot for Nextcloud Talk

🤖 **A specialized bot for intelligent meeting agenda management and real-time time tracking during Nextcloud Talk calls.**

🌍 **Now with multi-language support!** Available in English and German, with more languages coming soon.

⚡ **NEW in v1.2.0: Room-Level Time Monitoring!** Each Talk room can now have its own time monitoring settings, allowing different teams to customize warning thresholds and monitoring behavior while maintaining backward compatibility with global defaults. [Learn more →](docs/ROOM_TIME_MONITORING.md)

## Overview

The Agenda Bot is a comprehensive Nextcloud app that transforms how teams manage meeting agendas. Beyond basic agenda management, it provides intelligent time monitoring, permission-based access control, and automated progress tracking to ensure productive and efficient meetings.

## Key Features

### 📋 **Advanced Agenda Management**
- **Flexible item creation** - Natural syntax with multiple formats (`agenda:`, `topic:`, `item:`, `add:`, `insert:`)
- **Intelligent time parsing** - Supports various duration formats: `(5 min)`, `(1h)`, `(2 hours)`, `(90 min)`
- **Smart positioning** - Automatic position assignment or manual positioning with `#2.` syntax
- **Complete item lifecycle** - Add, reorder, mark complete/incomplete, remove items
- **Real-time status tracking** - Current item highlighting with time spent vs. planned

### ⏰ **Intelligent Time Monitoring**
- **Background monitoring** - Automated time tracking via background job
- **Room-level configuration** - Individual time monitoring settings per Talk room
- **Global fallback** - Rooms without specific settings use global defaults
- **Configurable warnings** - Custom warning and overtime threshold alerts per room
- **Call-aware notifications** - Only sends alerts during active calls
- **Time analytics** - Actual vs. planned duration tracking with visual indicators
- **Meeting efficiency insights** - Completion rates and timing statistics

### 🔐 **Permission-Based Access Control**
- **Granular permissions** - Different access levels for owners, moderators, users, and guests
- **Add item restrictions** - Only moderators, owners, and regular users can add items
- **View permissions** - All participants can view agenda status
- **Guest moderator support** - Special handling for guest moderators

### 🤖 **Smart Bot Integration**
- **Event-driven responses** - Responds to Talk call start/end events
- **Automatic agenda detection** - Parses agenda items from natural chat messages  
- **Comprehensive summaries** - Detailed meeting summaries with timing analytics
- **Cleanup automation** - Optional cleanup of completed items post-meeting via text command or emoji reactions (👍, ✅, 🧹)

### 🌍 **Multi-Language Support**
- **Separate language bots** - Choose your preferred language when adding the bot
- **Complete localization** - All messages, commands, and responses in your language
- **Currently supported**: English (en), German (de)
- **Automatic language detection** - Bot responds in the appropriate language
- **Nextcloud l10n standards** - Following official internationalization guidelines

## Installation

### Prerequisites
- Nextcloud 31 or later
- Nextcloud Talk app installed and enabled

### Manual Installation

1. **Download the app:**
   ```bash
   git clone https://github.com/lexioj/agenda_bot.git
   cd agenda_bot
   ```

2. **Copy to Nextcloud apps directory:**
   ```bash
   cp -r . /path/to/nextcloud/apps/agenda_bot/
   ```

3. **Set proper ownership:**
   ```bash
   chown -R www-data:www-data /path/to/nextcloud/apps/agenda_bot/
   ```

4. **Enable the app:**
   ```bash
   sudo -u www-data php /path/to/nextcloud/occ app:enable agenda_bot
   ```

5. **Add the bot to Talk rooms:**
   - Go to any Talk room
   - Open "Conversation settings"
   - Click "Bots"
   - Choose your preferred language: "Agenda bot (English)" or "Agenda bot (Deutsch)"
   - Enable the selected bot

## Usage

### Adding Agenda Items

During or before a meeting, add agenda items using these formats:

```
agenda: Project status review (15 min)
topic: #3 Budget discussion (20 min)
item: Next steps planning (10 min)
insert: #2 Quick updates (5 min)
add: Follow-up actions
```

**Syntax:**
- Start with `agenda:`, `topic:`, `item:`, `insert:`, or `add:`
- Optionally specify item position like `#3`
- Include the topic title
- Optionally specify duration in parentheses like `(15 min)`
- Default duration is 10 minutes if not specified

### Bot Commands

#### Status & Management
| Command | Description | Permissions | Example |
|---------|-------------|-------------|----------|
| `agenda status` / `agenda list` | Show current agenda with timing info | All participants | `agenda status` |
| `agenda help` | Show complete command help | All participants | `agenda help` |
| `agenda clear` | Clear all agenda items | Moderators, Owners | `agenda clear` |
| `agenda cleanup` | Remove completed items | Moderators, Owners | `agenda cleanup` |

#### Item Management
| Command | Description | Permissions | Example |
|---------|-------------|-------------|----------|
| `complete: X` / `done: X` / `close: X` | Mark specific item as completed | Moderators, Owners | `complete: 1` |
| `done:` (without number) | **Complete current item and auto-advance** | Moderators, Owners | `done:` |
| `incomplete: X` / `undone: X` / `reopen: X` | Reopen completed item | Moderators, Owners | `reopen: 2` |
| `next: X` | Set item X as current active item | Moderators, Owners | `next: 3` |
| `remove: X` / `delete: X` | Remove agenda item X | Moderators, Owners | `remove: 2` |

#### Reordering
| Command | Description | Permissions | Example |
|---------|-------------|-------------|----------|
| `reorder: A,B,C,D` | Reorder items to new positions | Moderators, Owners | `reorder: 2,1,4,3` |
| `move: X to Y` | Move item X to position Y | Moderators, Owners | `move: 3 to 1` |
| `swap: X,Y` | Swap positions of items X and Y | Moderators, Owners | `swap: 1,3` |

#### Room-Level Time Management (NEW in v1.2.0)
| Command | Description | Permissions | Example |
|---------|-------------|-------------|----------|
| `time config` | Show room time monitoring settings | Moderators, Owners | `time config` |
| `time enable` / `time disable` | Toggle time monitoring for this room | Moderators, Owners | `time enable` |
| `time warning 75` | Set time limit warning threshold (%) for room | Moderators, Owners | `time warning 75` |
| `time overtime 110` | Set overtime alert threshold (%) for room | Moderators, Owners | `time overtime 110` |
| `time thresholds 80 120` | Set both thresholds (warning, overtime) | Moderators, Owners | `time thresholds 70 110` |
| `time reset` | Reset room to global time monitoring defaults | Moderators, Owners | `time reset` |

### Example Workflow

1. **Before the meeting:**
   ```
   agenda: Welcome & introductions (5 min)
   agenda: Project updates (20 min)
   agenda: Budget review (15 min)
   agenda: Next steps
   ```
   "agenda list" command then shows:
   ```
   📋 Agenda Status
   📍 1. Welcome & introductions (5 min)
   📍 2. Project updates (20 min)
   📍 3. Budget review (15 min)
   📍 4. Next steps (10 min)
   ```

2. **During the meeting:**
   ```
   agenda status          # Check current agenda (shows ➡️ for current item)
   done:                  # Complete current item & auto-advance to next
   next: 3                # Skip active item and continue with specific agenda item
   complete: 4            # Complete specific item by number
   ```
   
   **The `done:` command is especially useful because it:**
   - ✅ Marks the current item as completed
   - ⏱️ Shows actual vs. planned duration
   - ➡️ Automatically advances to the next incomplete item
   - 📊 Provides seamless progress tracking

3. **After the meeting:**
   - Bot automatically generates agenda summary
   - Shows completed vs remaining items with timing analysis
   - Offers optional cleanup of completed items
   - Example Summary:
     ```
      📋 Meeting Agenda Summary
      Topic: Agenda Bot Test
      Total Agenda Items: 2
      Completed: 1 (100% ✅ / 0% ⏰)
      Remaining: 1
      
      ✅ Completed Items
      Item 1 (10-9 min) 👍
      📍 Remaining Items
      Item 2 (3 min)
      
      🧹 Remove completed items from agenda?
      Moderators/Owners: Reply with 'agenda cleanup' or react with 👍, ✅, or 🧹
      ```
     
## Database Schema

The Agenda Bot uses the `oc_ab_log_entries` table for all data storage, including room configurations:

### Table: `oc_ab_log_entries`

#### Core Fields
- `id` - Primary key (bigint, auto-increment)
- `server` - Server identifier (varchar, typically 'local')
- `token` - Talk room token (varchar, 64 chars)
- `type` - Entry type (varchar, 32 chars: agenda_item, room_config, start, end, attendee, message, time_warning)
- `details` - Entry content (longtext, JSON or plain text)

#### Entry Types
- `agenda_item` - Meeting agenda items with duration and status
- `room_config` - ⚡ Room-specific configuration (NEW in v1.2.0)
- `start` / `end` - Call session tracking
- `attendee` - Participant information
- `message` - Chat messages and summaries
- `time_warning` - Time monitoring alerts

### Agenda-Specific Fields
- `order_position` - Position in agenda (int, for agenda items)
- `duration_minutes` - Planned duration (int, for agenda items)
- `start_time` - Item start timestamp (bigint, for time tracking)
- `parent_id` - Reference to parent entry (bigint, for related entries)

### Status & Control Fields
- `conflict_resolved` - Position conflict status (boolean, default false)
- `warning_sent` - Time warning notification status (boolean, default false)
- `is_completed` - Completion status (boolean, default false)
- `completed_at` - Completion timestamp (bigint)

### Indexes for Performance
- `ab_log_entry_origin` - (server, token)
- `ab_log_entry_type` - (token, type)
- `ab_completion_status` - (token, type, is_completed)
- `ab_agenda_order` - (token, order_position)

### Room Configuration Storage ⚡ Smart Design in v1.2.0

Room configurations are stored as `room_config` entries in the existing table:

```json
{
  "time_monitoring": {
    "enabled": true,
    "warning_threshold": 0.8,
    "overtime_threshold": 1.2
  },
  "configured_by": "user123",
  "configured_at": 1693872000
}
```

**Benefits:**
- ✨ **Zero schema changes** - Uses existing table structure
- 🚀 **Instant deployment** - No migrations required
- 🔄 **Backward compatible** - Existing setups unaffected
- ⚡ **Efficient queries** - Existing indexes support room config lookups

## Development

### Project Structure

```
agenda_bot/
├── appinfo/
│   └── info.xml                  # App metadata & dependencies
├── lib/
│   ├── AppInfo/
│   │   └── Application.php       # Main app bootstrap
│   ├── Model/
│   │   ├── Bot.php              # Bot entity model with supported languages
│   │   ├── LogEntry.php         # Database entity with agenda fields
│   │   └── LogEntryMapper.php   # Database operations & queries
│   ├── Service/
│   │   ├── AgendaService.php    # Core agenda logic & room-aware time monitoring (l10n)
│   │   ├── BotService.php       # Multi-language bot registration & management
│   │   ├── CommandParser.php    # Message parsing & room-level time commands
│   │   ├── PermissionService.php # Access control & user permissions (l10n)
│   │   ├── RoomConfigService.php # ⚡ Room-specific configuration management
│   │   ├── TimeMonitorService.php # Time tracking & room-aware warnings (l10n)
│   │   └── SummaryService.php   # Meeting summaries & analytics (l10n)
│   ├── BackgroundJob/
│   │   └── AgendaTimeMonitorJob.php # Background time monitoring with room filtering
│   ├── Listener/
│   │   └── BotInvokeListener.php # Talk event handling & room-level commands
│   └── Migration/
│       ├── InstallBot.php       # Bot installation repair step
│       └── Version1000Date...php # Database schema migration
├── tests/                        # Unit tests for development
│   ├── Unit/Service/            # Service layer tests
│   ├── bootstrap.php            # Test bootstrap
│   └── phpunit.xml              # PHPUnit configuration
├── l10n/                         # 🌍 Translation files
│   ├── en.json                  # English translations (100+ strings)
│   └── de.json                  # German translations (100+ strings)
├── docs/                         # 📚 Project documentation
│   ├── MULTILINGUAL_SUPPORT.md  # 🌍 Internationalization documentation
│   └── ROOM_TIME_MONITORING.md  # ⚡ Room-level time monitoring guide
├── CHANGELOG.md                  # Version history & release notes
├── LICENSE                       # AGPL-3.0-or-later
└── README.md                     # This file
```

**Note:** Development and operational files (like `WARP.md`, `run_tests.sh`, and planning documents) are excluded from the public repository via `.gitignore` to maintain a clean distribution.

### Key Components

1. **AgendaService** - Core agenda functionality with room-aware time monitoring (🌍 l10n)
2. **RoomConfigService** - ⚡ Room-specific configuration management with global fallback
3. **TimeMonitorService** - Intelligent time tracking with room-specific thresholds (🌍 l10n)
4. **PermissionService** - Role-based access control and security (🌍 l10n)
5. **CommandParser** - Advanced message parsing with room-level time commands
6. **BotInvokeListener** - Talk event handling and room-level command routing (🌍 language detection)
7. **AgendaTimeMonitorJob** - Background monitoring with room filtering
8. **LogEntryMapper** - Optimized database operations with indexed queries
9. **SummaryService** - Meeting analytics and comprehensive summaries (🌍 l10n)
10. **BotService** - Multi-language bot registration and management (🌍 l10n)

### Development Guidelines

#### Adding New Features
1. **New Commands:** Add regex patterns to `CommandParser::parseCommand()`
2. **Agenda Operations:** Extend `AgendaService` with new methods (🌍 add l10n support)
3. **Permission Control:** Update `PermissionService` for new access rules (🌍 add l10n support)
4. **Time Features:** Enhance `TimeMonitorService` for monitoring capabilities (🌍 add l10n support)
5. **Database Changes:** Create migration files in `lib/Migration/`
6. **🌍 New Languages:** Add translation files in `l10n/{lang}.json` and update `Bot::SUPPORTED_LANGUAGES`

#### Architecture Principles
- **Service-oriented** - Each service has a single responsibility
- **Permission-first** - All operations check user permissions
- **Event-driven** - Responds to Talk events for seamless integration
- **Background processing** - Time monitoring runs independently
- **Database optimization** - Indexed queries for performance
- **🌍 Internationalization-ready** - All user-facing text supports localization

### Testing

Since this is a pure Nextcloud app without external dependencies, testing is done through:

- **Manual testing** in Nextcloud Talk rooms
- **PHP syntax validation** using standard PHP tools
- **Database migration testing** via Nextcloud's migration system
- **Permission testing** across different user roles

## Architecture

### Bot Integration

The Agenda Bot integrates with Nextcloud Talk through:

1. **Event Listeners** - Responds to Talk events (messages, call start/end)
2. **🌍 Multi-Language Bot Registration** - Registers separate bot instances for each supported language
3. **Webhook Handling** - Processes incoming messages and activities with language detection
4. **Language Detection** - Automatically detects user language from bot events

### Database Design

- **Normalized structure** - Single table with different entry types
- **Agenda-specific fields** - Position, duration, completion status
- **Efficient queries** - Indexed for common operations
- **Extensible design** - Easy to add new entry types

### Namespace Separation

The Agenda Bot uses the `OCA\AgendaBot` namespace and `ab_` database prefixes, ensuring no conflicts with the original Call Summary Bot.

## Advanced Features

### Permission System
| Role | Add Items | Manage Items | Moderate | View Status |
|------|-----------|--------------|-------------|----------|
| **Owner** | ✅ | ✅ | ✅ | ✅ |
| **Moderator** | ✅ | ✅ | ✅ | ✅ |
| **User** | ✅ | ❌ | ❌ | ✅ |
| **Guest** | ❌ | ❌ | ❌ | ✅ |
| **Guest Moderator** | ✅ | ✅ | ✅ | ✅ |

### Time Monitoring Features ⚡ Enhanced in v1.2.0
- **Room-level configuration** - Each room can have custom time monitoring settings
- **Global fallback** - Rooms without specific configuration use global defaults
- **Configurable thresholds** - Set custom warning and overtime percentages per room
- **Smart notifications** - Only alerts during active calls
- **Duplicate prevention** - Each warning type sent only once per item
- **Background processing** - Independent monitoring with room filtering via Nextcloud background jobs
- **Call-aware** - Automatically detects active calls before sending alerts
- **Moderator control** - Room moderators can configure monitoring without admin access

### Meeting Analytics
- **Completion rates** - Track % of agenda items completed
- **Time efficiency** - Compare planned vs. actual duration
- **Progress indicators** - Visual status with ✅ 📍 ➡️ emojis
- **Timing insights** - On-time (👍) vs. overtime (⏰) completion tracking
- **Summary exports** - Detailed meeting reports with statistics

## 🌍 Multi-Language Features

### Language Support
- **English (en)** - Complete with 74+ translated strings
- **German (de)** - Complete with 45+ translated strings
- **Framework ready** for additional languages

### Bot Registration
- **Separate bot instances** per language (e.g., "Agenda bot (English)", "Agenda bot (Deutsch)")
- **Localized descriptions** in bot selection interface
- **Language-specific URLs** for proper routing

### User Experience
- **Automatic language detection** from user's Nextcloud settings
- **Consistent localization** across all bot responses
- **Fallback to English** for missing translations
- **Help commands** display in user's preferred language

### Developer Features
- **Nextcloud l10n standards** - Uses official `IFactory` and `$l->t()` patterns
- **Easy language addition** - Add JSON file and update `Bot::SUPPORTED_LANGUAGES`
- **Translation validation** - Structured JSON format with pluralization rules
- **Comprehensive documentation** - See `MULTILINGUAL_SUPPORT.md` for implementation details

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## License

This project is licensed under the AGPL-3.0-or-later license.

## Support

- **Issues:** Report bugs and request features on GitHub
- **Documentation:** Check this README and inline code comments
- **Community:** Join the Nextcloud community discussions

## Technical Specifications

- **Nextcloud Version:** 31-32 (actively maintained)
- **Database:** Optimized with 5 indexes for performance
- **Background Jobs:** Configurable monitoring intervals (default: 120s)
- **Permissions:** Talk participant type integration
- **Events:** Full Talk integration (call start/end, messages)
- **Logging:** Comprehensive debug and error logging
- **Security:** Input validation and SQL injection protection

## Changelog

### Version 1.0.0
- **Core Features:** Complete agenda lifecycle management
- **Smart Time Monitoring:** Background job with configurable thresholds
- **Permission System:** Role-based access control with guest moderator support
- **Advanced Commands:** 15+ command patterns with flexible syntax
- **Meeting Analytics:** Timing insights and completion statistics
- **Database Optimization:** Indexed queries for performance
- **Talk Integration:** Full event handling and bot responses
- **Background Processing:** Independent time monitoring service

---

**Generated by Agenda bot v1.0.0** 📋
