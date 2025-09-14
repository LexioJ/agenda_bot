# Room-Level Bot Configuration

**Complete Guide for Agenda Bot v1.4.0**

This document provides comprehensive guidance for the revolutionary Room-Level Bot Configuration system introduced in Agenda Bot v1.4.0, which transforms each Talk room into a perfectly customized meeting assistant.

## 🎆 Overview

Room-Level Bot Configuration allows each Nextcloud Talk room to have its own unique bot behavior, replacing the one-size-fits-all approach with personalized, room-specific settings. Every aspect of the bot's behavior can be tailored to match your team's meeting culture and requirements.

### Key Benefits

- **🎯 Perfect Customization**: Each room has exactly the behavior your team needs
- **⚡ Instant Setup**: Configuration changes take effect immediately
- **🔄 Smart Inheritance**: Room settings override global defaults with intelligent fallback
- **🔒 Secure Control**: Only moderators and owners can modify room configurations
- **📊 Complete Transparency**: All configuration changes are audited with user and timestamp

## 🏗️ Architecture

### Configuration Storage

Room configurations are stored as JSON objects in the existing `oc_ab_log_entries` table using the `room_config` entry type. This design provides:

- **Zero schema changes** - Uses existing table structure
- **Atomic updates** - Partial configuration changes preserve other settings  
- **Efficient queries** - Existing indexes support fast lookups
- **Smart cleanup** - Empty configurations are automatically removed

### Configuration Hierarchy

```
Room-Specific Settings
        ↓ (if not set)
Global Defaults
        ↓ (if not set)  
Built-in Defaults
```

## 🎛️ Configuration Areas

### 1. ⏰ Time Monitoring

Control how the bot monitors meeting time and sends alerts.

#### Available Settings

| Setting | Range | Default | Description |
|---------|--------|---------|-------------|
| **Status** | enable/disable | enabled | Whether time monitoring is active |
| **Warning Threshold** | 10-95% | 80% | When to send first warning |
| **Overtime Threshold** | 105-300% | 120% | When to send overtime alert |

#### Commands

```bash
# View current configuration
config time

# Enable/disable monitoring
config time enable
config time disable

# Set warning threshold (percentage)
config time warning 75

# Set overtime threshold (percentage)  
config time overtime 110

# Set both thresholds at once
config time thresholds 70 120

# Reset to global defaults
config time reset
```

#### Examples

**Focused Sprint Planning**
```bash
config time thresholds 70 100  # Tighter time management
```

**Casual Team Standup**
```bash
config time warning 90         # Relaxed warning threshold
config time overtime 150       # Allow more flexibility
```

**Executive Board Meeting**
```bash
config time warning 85         # Conservative timing
config time overtime 105       # Minimal overtime allowed
```

### 2. 💬 Response Settings

Configure how verbose the bot's responses are.

#### Available Modes

| Mode | Text Responses | Emoji Reactions | Use Case |
|------|----------------|-----------------|----------|
| **Normal** | Full responses | Yes | Standard meetings, training |
| **Minimal** | Help/status only | Yes | Busy meetings, focused work |

#### Commands

```bash
# View current response mode
config response

# Switch to full responses  
config response normal

# Switch to minimal responses (reduces notifications)
config response minimal

# Reset to global defaults
config response reset
```

#### When to Use Minimal Mode

- **High-frequency meetings** (daily standups)
- **Focused work sessions** where notifications distract
- **Large meetings** where message volume should be reduced
- **Screen sharing scenarios** where bot messages interfere

### 3. 🚧 Agenda Limits

Control agenda size and default behaviors.

#### Available Settings

| Setting | Range | Default | Description |
|---------|--------|---------|-------------|
| **Max Items** | 5-100 | 50 | Total agenda items allowed |
| **Max Bulk** | 3-50 | 20 | Items per bulk operation |
| **Default Duration** | 1-120 min | 10 min | Duration when none specified |

#### Commands

```bash
# View current limits
config limits

# Set maximum total items
config limits max-items 30

# Set maximum bulk import size  
config limits max-bulk 15

# Set default duration for new items (minutes)
config limits default-duration 15

# Reset all limits to global defaults
config limits reset
```

#### Meeting Type Examples

**Quick Standup (15 min total)**
```bash
config limits max-items 5
config limits default-duration 3
```

**Project Review (2 hour meeting)**
```bash
config limits max-items 20
config limits default-duration 20
```

**Executive Board Meeting**
```bash
config limits max-items 15
config limits max-bulk 5      # Controlled agenda imports
config limits default-duration 25
```

### 4. 🤖 Auto-behaviors

Configure automatic actions during meeting lifecycle.

#### Available Behaviors

| Behavior | Default | Description |
|----------|---------|-------------|
| **Start Agenda** | enabled | Auto-set first item as current on call start |
| **Auto-cleanup** | disabled | Auto-remove completed items after meeting |
| **Generate Summary** | enabled | Create meeting summary on call end |

#### Commands

```bash
# View current auto-behaviors
config auto

# Enable/disable auto-start of first agenda item
config auto start-agenda enable
config auto start-agenda disable

# Enable/disable automatic cleanup of completed items
config auto cleanup enable  
config auto cleanup disable

# Enable/disable automatic summary generation
config auto summary enable
config auto summary disable

# Reset all auto-behaviors to global defaults
config auto reset
```

#### Meeting Flow Examples

**Structured Team Meeting**
```bash
config auto start-agenda enable    # Jump right into agenda
config auto cleanup enable         # Keep agenda clean
config auto summary enable         # Document outcomes
```

**Informal Brainstorming**
```bash
config auto start-agenda disable   # Manual control
config auto cleanup disable        # Keep all ideas visible
config auto summary enable         # Document results
```

### 5. 😀 Custom Emojis

Personalize visual indicators for agenda items.

#### Available Emoji Types

| Type | Default | Usage |
|------|---------|--------|
| **Current Item** | 🗣️ | Shows which item is being discussed |
| **Completed** | ✅ | Marks finished items |
| **Pending** | 📍 | Shows items not yet started |
| **On Time** | 👍 | Timing indicator for good progress |
| **Time Warning** | ⏰ | Shown when time limits approached |

#### Commands

```bash
# View current emoji set
config emojis

# Set custom emoji for specific type
config emojis current-item 🎯
config emojis completed 🎉
config emojis pending 📋
config emojis on-time 👌
config emojis time-warning ⚠️

# Reset all emojis to defaults
config emojis reset
```

#### Theme Examples

**Professional Meeting**
```bash
config emojis current-item 🔷
config emojis completed ✅
config emojis pending ⬜
config emojis on-time ✔️
config emojis time-warning ⏱️
```

**Creative Team**
```bash
config emojis current-item 🎨
config emojis completed 🌟
config emojis pending 💡
config emojis on-time 🚀
config emojis time-warning 🔥
```

**Development Team**
```bash
config emojis current-item 💻
config emojis completed ✨
config emojis pending 📦
config emojis on-time 🟢
config emojis time-warning 🟡
```

## 📋 Complete Configuration Overview

Use the `config show` command to see all room configuration at once:

```bash
config show
```

### Sample Output

```
### ⚙️ Room Configuration

##### 🕙 Time Monitoring
• Status: ✅ Enabled
• Warning threshold: 75% of planned time
• Overtime threshold: 110% of planned time  
• Configured by: ✏️ john.doe (2025-01-15 14:30)
💡 Use `config time` for time configuration help

##### 💬 Response
• Response mode: 😴 Minimal mode — Emoji reactions only
• Text responses: Only for help, status, and call notifications
• Configured by: 🌐 Global defaults
💡 Use `config response` for response configuration help

##### 🚧 Agenda Limits
• Max total items: 30 items
• Max bulk operation: 15 items
• Default item duration: 15 minutes
• Configured by: ✏️ jane.smith (2025-01-15 10:15)
💡 Use `config limits` for limits configuration help

##### 🤖 Auto-behaviors
• Start agenda on call: ✅ Enabled
• Auto-cleanup completed: ❌ Disabled
• Generate summaries: ✅ Enabled
• Configured by: 🌐 Global defaults
💡 Use `config auto` for auto-behaviors configuration help

##### 😀 Custom Emojis
• Current agenda item: 🎯
• Completed agenda item: 🎉
• Pending agenda item: 📋
• On time icon: 👌
• Time warning icon: ⚠️
• Configured by: ✏️ alice.doe (2025-01-15 09:45)
💡 Use `config emojis` for custom emojis configuration help

---
🔒 Only moderators and owners can modify room configuration
```

## 🏢 Meeting Type Templates

### Daily Standup (15 minutes)

**Goal**: Quick status updates with minimal distractions

```bash
# Tight time management
config time thresholds 70 100

# Reduce notification noise  
config response minimal

# Limit agenda size
config limits max-items 8
config limits default-duration 2

# Streamlined flow
config auto start-agenda enable
config auto cleanup enable
config auto summary disable

# Clean, professional emojis
config emojis current-item 🔷
config emojis completed ✅
```

### Sprint Planning (2 hours)

**Goal**: Detailed planning with comprehensive documentation

```bash
# Balanced time monitoring
config time thresholds 80 125

# Full documentation
config response normal

# Support detailed planning
config limits max-items 25
config limits default-duration 20

# Comprehensive tracking
config auto start-agenda enable
config auto cleanup disable
config auto summary enable

# Development-themed emojis
config emojis current-item 💻
config emojis completed ✨
config emojis pending 📦
```

### Executive Board Meeting

**Goal**: Formal structure with strict time management

```bash
# Conservative time management
config time warning 85
config time overtime 105

# Full professional responses
config response normal

# Controlled agenda
config limits max-items 12
config limits max-bulk 5
config limits default-duration 25

# Formal meeting flow
config auto start-agenda enable
config auto cleanup disable
config auto summary enable

# Professional appearance
config emojis completed ✔️
config emojis time-warning ⏱️
```

### Creative Brainstorming

**Goal**: Free-flowing discussion with flexible structure

```bash
# Relaxed time monitoring
config time warning 95
config time overtime 150

# Full creative responses
config response normal

# Flexible structure
config limits max-items 40
config limits default-duration 8

# Manual control
config auto start-agenda disable
config auto cleanup disable
config auto summary enable

# Creative theme
config emojis current-item 🎨
config emojis completed 🌟
config emojis pending 💡
```

## 🔧 Advanced Configuration

### Configuration Reset Strategies

#### Individual Section Reset

```bash
# Reset only time monitoring
config time reset

# Reset only response behavior
config response reset

# Reset only limits
config limits reset

# Reset only auto-behaviors
config auto reset

# Reset only emojis
config emojis reset
```

#### Complete Room Reset

To reset all room configuration and return to global defaults:

1. Reset each section individually (shown above)
2. Verify with `config show`
3. All sections should show "🌐 Global defaults"

### Configuration Backup & Restore

#### Creating a Configuration Profile

1. **Document current settings**:
   ```bash
   config show  # Copy output to document
   ```

2. **Save command sequence**:
   ```bash
   # Save these commands to recreate this configuration:
   config time thresholds 75 110
   config response minimal  
   config limits default-duration 15
   # ... etc
   ```

#### Applying Configuration Profiles

Create reusable command sequences for different meeting types:

**profiles/standup.txt**
```bash
config time thresholds 70 100
config response minimal
config limits max-items 8
config limits default-duration 2
config auto start-agenda enable
config auto cleanup enable
```

## 🔍 Troubleshooting

### Common Issues

#### Configuration Not Taking Effect

**Symptoms**: Changes don't seem to apply
**Solutions**:
1. Verify moderator permissions: `agenda help`
2. Check current status: `config show`
3. Confirm app version: Should show 1.4.0+

#### Permission Denied

**Symptoms**: "Only moderators and owners can modify room configuration"
**Solutions**:
1. Verify you have moderator/owner role in the Talk room
2. Ask room owner to grant moderator permissions
3. Contact Nextcloud admin if needed

#### Global Defaults Not Working

**Symptoms**: Room shows custom settings when expecting global defaults
**Solutions**:
1. Check if room has override: `config show`
2. Reset specific section: `config time reset`
3. Verify global settings with Nextcloud admin

#### Background Jobs Not Running

**Symptoms**: Time monitoring alerts not sent
**Solutions**:
1. Verify time monitoring enabled: `config time`
2. Check background job status (admin only)
3. Ensure agenda items have durations set

### Diagnostic Commands

```bash
# Check complete room configuration
config show

# Verify time monitoring status
config time

# Test current agenda with timing
agenda status

# View help to confirm permissions
agenda help
```

### Getting Help

1. **In-app help**: `agenda help`
2. **Configuration overview**: `config show`  
3. **Specific area help**: `config time`, `config limits`, etc.
4. **GitHub issues**: Report bugs and feature requests
5. **Nextcloud community**: General support discussions

## 🔮 Future Enhancements

### Planned Features

- **Configuration Templates**: Pre-built meeting type configurations
- **Bulk Room Configuration**: Admin tools for managing multiple rooms
- **Advanced Scheduling**: Time-based configuration changes
- **Calendar Integration**: Sync with Nextcloud Calendar for automatic settings
- **Analytics Dashboard**: Room-specific usage reports and insights
- **Configuration Import/Export**: JSON-based configuration management

### API Extensions

Future versions may include REST API endpoints for:
- Programmatic configuration management
- Bulk room operations
- Configuration template management
- Analytics and reporting

## 📊 Performance Considerations

### Storage Impact

- **Minimal overhead**: JSON configuration objects are lightweight (~1KB each)
- **Efficient queries**: Uses existing database indexes for fast lookups
- **Smart cleanup**: Empty configurations are automatically removed
- **Atomic updates**: Partial changes preserve other settings efficiently

### Background Processing

- **Room filtering**: Background jobs only process rooms with monitoring enabled
- **Intelligent caching**: Configuration values are cached for performance
- **Minimal impact**: Configuration lookups add <1ms to response times

## 🔐 Security & Privacy

### Access Control

- **Permission enforcement**: All configuration changes require moderator/owner permissions
- **Audit trail**: Complete logging of all configuration changes with user attribution
- **Data isolation**: Room configurations cannot affect other rooms
- **Validation**: All input values are validated and sanitized

### Data Handling

- **Local storage**: All configuration data stored in Nextcloud database
- **No external calls**: Configuration system is completely self-contained
- **Privacy preservation**: No personally identifiable information in config data
- **Secure defaults**: All default values are secure and conservative

---

**🎉 Ready to transform your meetings?** Start with `config show` to see your current room configuration, then customize each area to match your team's unique needs!

For the latest documentation and updates, visit the [Agenda Bot GitHub repository](https://github.com/lexioj/agenda_bot).