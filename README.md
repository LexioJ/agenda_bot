# Agenda Bot for Nextcloud Talk

🤖 **A specialized bot for intelligent meeting agenda management and real-time time tracking during Nextcloud Talk calls.**

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
- **Configurable warnings** - 80%, 100%, and overtime threshold alerts
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
- **Cleanup automation** - Optional cleanup of completed items post-meeting

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
   - Click the participants list
   - Click "Add bot"
   - Select "Agenda bot"

## Usage

### Adding Agenda Items

During or before a meeting, add agenda items using these formats:

```
agenda: Project status review (15 min)
topic: Budget discussion (20 min)
item: Next steps planning (10 min)
insert: Quick updates (5 min)
add: Follow-up actions
```

**Syntax:**
- Start with `agenda:`, `topic:`, `item:`, `insert:`, or `add:`
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
| `complete: X` / `done: X` / `close: X` | Mark specific item as completed | Moderators, Owners, Users | `complete: 1` |
| `done:` (without number) | **Complete current item and auto-advance** | All participants | `done:` |
| `incomplete: X` / `undone: X` / `reopen: X` | Reopen completed item | Moderators, Owners, Users | `reopen: 2` |
| `next: X` | Set item X as current active item | Moderators, Owners | `next: 3` |
| `remove: X` / `delete: X` | Remove agenda item X | Moderators, Owners, Users | `remove: 2` |

#### Reordering
| Command | Description | Permissions | Example |
|---------|-------------|-------------|----------|
| `reorder: A,B,C,D` | Reorder items to new positions | Moderators, Owners, Users | `reorder: 2,1,4,3` |
| `move: X to Y` | Move item X to position Y | Moderators, Owners, Users | `move: 3 to 1` |
| `swap: X,Y` | Swap positions of items X and Y | Moderators, Owners, Users | `swap: 1,3` |

#### Time Management (Optional)
| Command | Description | Permissions | Example |
|---------|-------------|-------------|----------|
| `time config` | Show time monitoring settings | Moderators, Owners | `time config` |
| `time enable` / `time disable` | Toggle time monitoring | Moderators, Owners | `time enable` |
| `time thresholds 80 100 120` | Set warning thresholds (%) | Moderators, Owners | `time thresholds 70 100 130` |

### Example Workflow

1. **Before the meeting:**
   ```
   agenda: Welcome & introductions (5 min)
   agenda: Project updates (20 min)
   agenda: Budget review (15 min)
   agenda: Next steps (10 min)
   ```

2. **During the meeting:**
   ```
   agenda status          # Check current agenda (shows ➡️ for current item)
   done:                  # Complete current item & auto-advance to next
   done:                  # Complete next item & auto-advance
   complete: 4            # Or complete specific item by number
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

## Database Schema

The Agenda Bot uses the `oc_ab_log_entries` table with these key columns:

### Core Fields
- `id` - Primary key (bigint, auto-increment)
- `server` - Server identifier (varchar, typically 'local')
- `token` - Talk room token (varchar, 64 chars)
- `type` - Entry type (varchar, 32 chars: agenda_item, start, end, attendee, message, time_warning)
- `details` - Entry content (longtext, JSON or plain text)

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
│   │   ├── Bot.php              # Bot entity model
│   │   ├── LogEntry.php         # Database entity with agenda fields
│   │   └── LogEntryMapper.php   # Database operations & queries
│   ├── Service/
│   │   ├── AgendaService.php    # Core agenda logic & item management
│   │   ├── BotService.php       # Bot registration & management
│   │   ├── CommandParser.php    # Message parsing & command detection
│   │   ├── PermissionService.php # Access control & user permissions
│   │   ├── TimeMonitorService.php # Time tracking & warning system
│   │   └── SummaryService.php   # Meeting summaries & analytics
│   ├── BackgroundJob/
│   │   └── AgendaTimeMonitorJob.php # Background time monitoring
│   ├── Listener/
│   │   └── BotInvokeListener.php # Talk event handling
│   └── Migration/
│       ├── InstallBot.php       # Bot installation repair step
│       └── Version1000Date...php # Database schema migration
├── LICENSE                       # AGPL-3.0-or-later
└── README.md                     # This file
```

### Key Components

1. **AgendaService** - Core agenda functionality, item lifecycle management
2. **TimeMonitorService** - Intelligent time tracking and warning system
3. **PermissionService** - Role-based access control and security
4. **CommandParser** - Advanced message parsing with 15+ command patterns
5. **BotInvokeListener** - Talk event handling and bot responses
6. **AgendaTimeMonitorJob** - Background monitoring service
7. **LogEntryMapper** - Optimized database operations with indexed queries
8. **SummaryService** - Meeting analytics and comprehensive summaries

### Development Guidelines

#### Adding New Features
1. **New Commands:** Add regex patterns to `CommandParser::parseCommand()`
2. **Agenda Operations:** Extend `AgendaService` with new methods
3. **Permission Control:** Update `PermissionService` for new access rules
4. **Time Features:** Enhance `TimeMonitorService` for monitoring capabilities
5. **Database Changes:** Create migration files in `lib/Migration/`

#### Architecture Principles
- **Service-oriented** - Each service has a single responsibility
- **Permission-first** - All operations check user permissions
- **Event-driven** - Responds to Talk events for seamless integration
- **Background processing** - Time monitoring runs independently
- **Database optimization** - Indexed queries for performance

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
2. **Bot Registration** - Registers itself as a Talk bot during installation
3. **Webhook Handling** - Processes incoming messages and activities

### Database Design

- **Normalized structure** - Single table with different entry types
- **Agenda-specific fields** - Position, duration, completion status
- **Efficient queries** - Indexed for common operations
- **Extensible design** - Easy to add new entry types

### Namespace Separation

The Agenda Bot uses the `OCA\AgendaBot` namespace and `ab_` database prefixes, ensuring no conflicts with the original Call Summary Bot.

## Advanced Features

### Permission System
| Role | Add Items | Manage Items | View Status | Moderate |
|------|-----------|--------------|-------------|----------|
| **Owner** | ✅ | ✅ | ✅ | ✅ |
| **Moderator** | ✅ | ✅ | ✅ | ✅ |
| **User** | ✅ | ✅ | ✅ | ❌ |
| **Guest** | ❌ | ❌ | ✅ | ❌ |
| **Guest Moderator** | ✅ | ✅ | ✅ | ✅ |

### Time Monitoring Features
- **Configurable thresholds** - Set custom warning percentages (default: 80%, 100%, 120%)
- **Smart notifications** - Only alerts during active calls
- **Duplicate prevention** - Each warning type sent only once per item
- **Background processing** - Independent monitoring via Nextcloud background jobs
- **Call-aware** - Automatically detects active calls before sending alerts

### Meeting Analytics
- **Completion rates** - Track % of agenda items completed
- **Time efficiency** - Compare planned vs. actual duration
- **Progress indicators** - Visual status with ✅ ⏸️ ➡️ emojis
- **Timing insights** - On-time (👍) vs. overtime (⏰) completion tracking
- **Summary exports** - Detailed meeting reports with statistics

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
