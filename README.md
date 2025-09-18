# Agenda Bot for Nextcloud Talk

🤖 **A specialized bot for intelligent meeting agenda management and real-time time tracking during Nextcloud Talk calls.**

🎆 **NEW in v1.4.0: Complete Room-Level Bot Configuration!** Transform each Talk room into a perfectly customized meeting assistant with 5 comprehensive configuration areas.

⚡ **v1.3.0 introduced Bulk Agenda Creation** - Import existing agendas from calendar invitations or meeting templates.

⏰ **v1.2.0 introduced Advanced Time Monitoring** - Room-specific time tracking with custom warning thresholds and intelligent automation.

🌍 **v1.1.0 introduced Multi-language Support** - Available in English and German, with complete localization for all features.


## Overview

The Agenda Bot is a comprehensive Nextcloud app that transforms how teams manage meeting agendas. Beyond basic agenda management, it provides intelligent time monitoring, permission-based access control, and automated progress tracking to ensure productive and efficient meetings.

## Screenshots
![Agenda Bot Meeting Flow](https://github.com/LexioJ/agenda_bot/blob/main/docs/agenda_bot_meeting_flow.png)

## Key Features

### ⚙️ **Room-Level Bot Configuration (NEW in v1.4.0)**
- **Complete Customization** - Five comprehensive configuration areas for personalized room experiences
- **Time Monitoring Config** - Custom warning thresholds, overtime alerts, and monitoring controls per room
- **Response Behavior** - Choose between full text responses or minimal emoji-only mode to reduce notifications
- **Agenda Limits** - Configure max items (5-100), bulk operations (3-50), and default durations (1-120 min)
- **Auto-behaviors** - Control automatic start, cleanup, and summary generation behaviors
- **Custom Emojis** - Personalize agenda item status indicators with room-specific emoji sets
- **Unified Interface** - Simple `config show` command displays complete room configuration overview
- **Smart Inheritance** - Room settings override global defaults with intelligent fallback

### 📋 **Advanced Agenda Management**
- **Flexible item creation** - Natural syntax with multiple formats (`agenda:`, `topic:`, `item:`, `add:`, `insert:`)
- **Bulk agenda import** - Create multiple items at once from structured lists
- **Intelligent time parsing** - Supports various duration formats: `(5 min)`, `(1h)`, `(2 hours)`, `(90 min)`
- **Smart positioning** - Automatic position assignment or manual positioning with `#2.` syntax
- **Complete item lifecycle** - Add, reorder, mark complete/incomplete, remove items
- **Real-time status tracking** - Current item highlighting with time spent vs. planned

### ⏰ **Intelligent Time Monitoring**
- **Room-specific configuration** - Individual time monitoring settings per Talk room with `config time` commands
- **Custom thresholds** - Configurable warning (10-95%) and overtime (105-300%) thresholds per room  
- **Background monitoring** - Automated time tracking via background job with call-aware notifications
- **Flexible control** - Enable/disable monitoring per room without affecting other rooms
- **Global fallback** - Rooms without specific settings use global defaults with seamless inheritance
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
- **Complete localization** - All messages, commands, and configuration options in your language  
- **Currently supported**: English (en), German (de) with 200+ translation keys each
- **Automatic language detection** - Bot responds in the appropriate language
- **Nextcloud l10n standards** - Following official internationalization guidelines

## Installation

### Prerequisites
- Nextcloud 31 or later
- Nextcloud Talk app installed and enabled

### From Nextcloud App Store (Recommended)

Since this bot is written as a Nextcloud app, simply search for "Agenda Bot" in the Apps section of your Nextcloud server admin interface, or download it directly from the [Nextcloud App Store](https://apps.nextcloud.com/apps/agenda_bot).

**Via Nextcloud Admin Interface:**
   - Log in as admin to your Nextcloud instance
   - Go to **Apps** in the admin menu
   - Search for "Agenda Bot"
   - Click **Download and enable** to install

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

#### Bulk Agenda Creation

For quickly importing existing agendas from calendar invitations or meeting templates, use the bulk format:

```
agenda:
- Welcome & introductions (5 min)
- Project status review (20 min)
- Budget discussion (15 min)  
- Next steps planning (10 min)
- Closing remarks
```

**Bulk Format Features:**
- Creates multiple items with a single message
- Supports both `-` and `*` bullet markers
- Same time format support: `(5 min)`, `(1h)`, `(30m)`, etc.
- Automatic sequential positioning
- Maximum 20 items per bulk operation
- Perfect for copy-pasting agendas from external sources

### Bot Commands

#### Status & Management
| Command | Description | Permissions | Example |
|---------|-------------|-------------|----------|
| `agenda status` / `agenda list` | Show current agenda with timing info | All participants | `agenda status` |
| `agenda help` | Show complete command help | All participants | `agenda help` |
| `agenda clear` | Clear all agenda items | Moderators, Owners | `agenda clear` |
| `agenda cleanup` | Remove completed items | Moderators, Owners | `agenda cleanup` |
| `agenda reset` | **Reset all items to incomplete** | Moderators, Owners | `agenda reset` |

#### Item Management
| Command | Description | Permissions | Example |
|---------|-------------|-------------|----------|
| `complete: X` / `done: X` / `close: X` | Mark specific item as completed | Moderators, Owners | `complete: 1` |
| `done:` (without number) | **Complete current item and auto-advance** | Moderators, Owners | `done:` |
| `incomplete: X` / `undone: X` / `reopen: X` | Reopen completed item | Moderators, Owners | `reopen: 2` |
| `next: X` | Set item X as current active item | Moderators, Owners | `next: 3` |
| `change: X Title (duration)` | **Edit existing item title and/or duration** | Moderators, Owners | `change: 2 New title (25 min)` |
| `remove: X` / `delete: X` | Remove agenda item X | Moderators, Owners | `remove: 2` |

#### Reordering
| Command | Description | Permissions | Example |
|---------|-------------|-------------|----------|
| `reorder: A,B,C,D` | Reorder items to new positions | Moderators, Owners | `reorder: 2,1,4,3` |
| `move: X to Y` | Move item X to position Y | Moderators, Owners | `move: 3 to 1` |
| `swap: X,Y` | Swap positions of items X and Y | Moderators, Owners | `swap: 1,3` |

#### Room-Level Bot Configuration (NEW in v1.4.0)
| Command | Description | Permissions | Example |
|---------|-------------|-------------|----------|
| `config show` | **Display complete room configuration overview** | All participants | `config show` |
| `config time` | Show/configure time monitoring settings | Moderators, Owners | `config time` |
| `config time enable/disable` | Toggle time monitoring for this room | Moderators, Owners | `config time enable` |
| `config time warning X` | Set warning threshold (10-95%) | Moderators, Owners | `config time warning 75` |
| `config time overtime X` | Set overtime threshold (105-300%) | Moderators, Owners | `config time overtime 110` |
| `config time thresholds X Y` | Set both warning and overtime thresholds | Moderators, Owners | `config time thresholds 70 110` |
| `config time reset` | Reset time monitoring to global defaults | Moderators, Owners | `config time reset` |
| `config response normal/minimal` | **Switch between full/minimal response modes** | Moderators, Owners | `config response minimal` |
| `config limits max-items X` | Set maximum agenda items (5-100) | Moderators, Owners | `config limits max-items 30` |
| `config limits default-duration X` | Set default item duration (1-120 min) | Moderators, Owners | `config limits default-duration 15` |
| `config auto start-agenda enable` | **Auto-start agenda on call begin** | Moderators, Owners | `config auto start-agenda enable` |
| `config auto cleanup enable` | Auto-remove completed items after meeting | Moderators, Owners | `config auto cleanup enable` |
| `config emojis current-item 🎯` | **Customize agenda item status emojis** | Moderators, Owners | `config emojis completed 🎉` |

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

### Recurring Meetings Workflow

For weekly stand-ups, monthly reviews, or other recurring meetings:

**Reusing the same agenda:**
```
# After completing last week's meeting, reset all items for next week
agenda reset              # All items become incomplete again
agenda status             # Check the refreshed agenda
```

**Benefits of `agenda reset`:**
- ✅ **Efficient reuse** - No need to recreate agenda items
- ✅ **One command** - Reset all items instantly vs. individual `undone:` commands  
- ✅ **Time tracking reset** - Warnings re-enabled for all items
- ✅ **Perfect for templates** - Standardized agendas for recurring meetings
     
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

### Room Configuration Storage ⚡ Enhanced in v1.4.0

Room configurations are stored as `room_config` entries with comprehensive JSON structure:

```json
{
  "time_monitoring": {
    "enabled": true,
    "warning_threshold": 0.75,
    "overtime_threshold": 1.1,
    "configured_by": "user123",
    "configured_at": 1694618400
  },
  "response_settings": {
    "response_mode": "minimal",
    "configured_by": "user123",
    "configured_at": 1694618400
  },
  "agenda_limits": {
    "max_items": 30,
    "max_bulk_items": 15,
    "default_duration": 15,
    "configured_by": "user123",
    "configured_at": 1694618400
  },
  "auto_behaviors": {
    "start_agenda": true,
    "cleanup": false,
    "summary": true,
    "configured_by": "user123",
    "configured_at": 1694618400
  },
  "custom_emojis": {
    "current_item": "🎯",
    "completed": "🎉",
    "pending": "📋",
    "on_time": "👍",
    "time_warning": "⚠️",
    "configured_by": "user123",
    "configured_at": 1694618400
  }
}
```

**v1.4.0 Benefits:**
- 🎆 **5 Configuration Areas** - Comprehensive room customization
- ✨ **Smart Defaults** - Intelligent fallback to global settings
- 🚀 **Atomic Updates** - Partial configuration changes preserve other settings
- 🔄 **Backward Compatible** - All existing configurations preserved
- ⚡ **Efficient Storage** - Optimized JSON structure with validation

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
│   │   ├── CommandParser.php    # Message parsing & modification commands
│   │   ├── PermissionService.php # Access control & user permissions (l10n)
│   │   ├── RoomConfigService.php # Room-specific configuration management
│   │   ├── SummaryService.php   # Meeting summaries & analytics (l10n)
│   │   ├── TimeMonitorService.php # Time tracking & room-aware warnings (l10n)
│   │   └── TimingUtilityService.php # 🚀 Centralized timing calculations & formatting
│   ├── BackgroundJob/
│   │   └── AgendaTimeMonitorJob.php # Background time monitoring with room filtering
│   ├── Listener/
│   │   └── BotInvokeListener.php # Talk event handling & room-level commands
│   └── Migration/
│       ├── InstallBot.php       # Bot installation repair step
│       └── Version1000Date...php # Database schema migration
├── l10n/                         # 🌍 Translation files
│   ├── en.json                  # English translations (100+ strings)
│   └── de.json                  # German translations (100+ strings)
├── docs/                         # 📚 Project documentation
│   ├── MULTILINGUAL_SUPPORT.md  # 🌍 Internationalization documentation
│   ├── ROOM_TIME_MONITORING.md  # ⚡ Room-level time monitoring guide
│   └── SILENT_CALL_HANDLING.md  # 🔇 Silent call detection & response behavior
├── CHANGELOG.md                  # Version history & release notes
├── LICENSE                       # AGPL-3.0-or-later
└── README.md                     # This file
```

**Note:** Development and operational files (like `WARP.md`, `run_tests.sh`, and planning documents) are excluded from the public repository via `.gitignore` to maintain a clean distribution.

### Key Components

1. **AgendaService** - Core agenda functionality with item modification support (🌍 l10n)
2. **TimingUtilityService** - 🚀 Centralized timing calculations, formatting & display options
3. **RoomConfigService** - Room-specific configuration management with global fallback
4. **TimeMonitorService** - Intelligent time tracking with room-specific thresholds (🌍 l10n)
5. **PermissionService** - Role-based access control and security (🌍 l10n)
6. **CommandParser** - Advanced message parsing with modification commands
7. **BotInvokeListener** - Talk event handling and command routing (🌍 language detection)
8. **AgendaTimeMonitorJob** - Background monitoring with room filtering
9. **SummaryService** - Meeting analytics and comprehensive summaries (🌍 l10n)
10. **LogEntryMapper** - Optimized database operations with indexed queries
11. **BotService** - Multi-language bot registration and management (🌍 l10n)

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

### Time Monitoring Features ⚡ Enhanced in v1.4.0
- **Unified Configuration** - Complete `config time` command suite for all time monitoring settings
- **Flexible Thresholds** - Configurable warning (10-95%) and overtime (105-300%) percentages per room
- **Room-specific Control** - Enable/disable monitoring per room without affecting other rooms
- **Global Fallback** - Intelligent inheritance from global settings when room config not set
- **Smart Notifications** - Call-aware alerts sent only during active meetings
- **Duplicate Prevention** - Each warning type sent only once per item
- **Background Processing** - Independent monitoring with room filtering via Nextcloud background jobs
- **Moderator Control** - Room moderators can configure monitoring without admin intervention
- **Backward Compatibility** - Legacy `time xxx` commands remain fully functional

### Meeting Analytics
- **Completion rates** - Track % of agenda items completed
- **Time efficiency** - Compare planned vs. actual duration
- **Progress indicators** - Visual status with ✅ 📍 ➡️ emojis
- **Timing insights** - On-time (👍) vs. overtime (⏰) completion tracking
- **Summary exports** - Detailed meeting reports with statistics

## 🌍 Multi-Language Features

### Language Support ⚡ Enhanced in v1.4.0
- **English (en)** - Complete with 200+ translated strings including all configuration areas
- **German (de)** - Complete with 200+ translated strings including all configuration areas
- **Framework ready** for additional languages with comprehensive translation infrastructure

### v1.4.0 Localization Enhancements
- **Configuration Commands** - All 5 configuration areas fully translated
- **Help System Integration** - Contextual help and examples in user's language
- **Error Messages** - Comprehensive error handling with localized feedback
- **Visual Formatting** - Language-appropriate formatting and cultural adaptations

### Bot Registration
- **Separate bot instances** per language (e.g., "Agenda bot (English)", "Agenda bot (Deutsch)")
- **Localized descriptions** in bot selection interface
- **Language-specific URLs** for proper routing

### User Experience
- **Automatic language detection** from user's Nextcloud settings and room context
- **Consistent localization** across all bot responses and configuration interfaces
- **Fallback to English** for missing translations with graceful degradation
- **Dynamic language switching** - Commands respond in appropriate language per room
- **Help commands** display comprehensive examples in user's preferred language

### Developer Features
- **Nextcloud l10n standards** - Uses official `IFactory` and `$l->t()` patterns throughout
- **Easy language addition** - Add JSON file and update `Bot::SUPPORTED_LANGUAGES`
- **Translation validation** - Structured JSON format with proper pluralization rules
- **Configuration translations** - All new v1.4.0 features include complete translation keys
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

---

**Generated by Agenda bot v1.4.1** 📋
