# Silent Call Handling

## Overview

Starting with version 1.3.4, the Agenda Bot intelligently detects when calls are started silently and adapts its behavior accordingly to respect the user's intention for a quiet start.

## How It Works

### Silent Call Detection

The bot analyzes system message events from Nextcloud Talk to determine if a call was started silently:

1. **System Message Analysis**: When a `call_started` activity event occurs, the bot examines the system message metadata
2. **Silent Flag Detection**: Looks for the `silent` parameter in the message metadata that Talk uses internally to track silent calls
3. **Fallback Detection**: As a backup, searches for "silent" keywords in translated message parameters

### Notification Behavior

**Silent Calls:**
- Agenda status messages are sent **silently** (no notifications generated)
- Users won't receive chat notifications when the bot posts agenda information
- Preserves the quiet nature of silent call starts

**Regular Calls:**
- Agenda status messages are sent with **normal notification behavior**
- Users receive notifications as expected for bot responses
- Maintains standard meeting flow

## Code Implementation

### Key Method: `isCallStartedSilently()`

```php
private function isCallStartedSilently(array $data): bool {
    // Analyzes bot event data to detect silent calls
    // Returns true if call was started silently
}
```

### Integration Point

The detection is integrated into the call handling logic:

```php
if ($data['object']['name'] === 'call_started') {
    $isCallSilent = $this->isCallStartedSilently($data);
    
    // Send agenda status with appropriate notification behavior
    $event->addAnswer($status, $isCallSilent); // true = silent
}
```

## User Experience

### Before v1.3.4
- Silent calls would still trigger notification-heavy agenda messages
- Users would receive unwanted notifications despite choosing to start quietly

### After v1.3.4
- Silent calls result in silent bot responses
- Normal calls maintain standard notification behavior
- Bot behavior matches user intent

## Technical Details

### Event Data Structure

The bot receives ActivityPub-formatted events from Talk's bot system:

```json
{
  "type": "Activity",
  "object": {
    "name": "call_started",
    "content": "{\"message\":\"call_started\",\"parameters\":{\"silent\":true}}"
  }
}
```

### Detection Logic

1. **Primary Check**: Look for `messageData['parameters']['silent'] === true`
2. **Fallback Check**: Search for "silent" keywords in message parameters
3. **Safety Check**: Only applies to `call_started` events

### Logging

Enhanced debug logging helps track detection:

```php
$this->logger->debug('Could not parse call event content as JSON', [
    'content' => $content,
    'error' => $e->getMessage(),
    'object_name' => $data['object']['name'] ?? 'unknown'
]);
```

## Benefits

✅ **Respects User Intent**: Silent calls remain silent
✅ **Maintains Functionality**: Agenda information still provided  
✅ **Better UX**: No unexpected notifications during quiet starts
✅ **Backward Compatible**: Existing behavior unchanged for normal calls

## Testing

To test the feature:

1. Start a call silently in a Talk room with the bot
2. Verify the bot posts agenda status without notifications
3. Start a regular call and confirm notifications work normally
4. Check the Nextcloud log for any detection issues

The feature automatically adapts to the call type without any user configuration required.
