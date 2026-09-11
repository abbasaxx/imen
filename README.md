# Imen.Chat — Design Specification

> **Live:** The app is now hosted at [https://imen.chat](https://imen.chat) — you can test it there.

> **Setup:** Before running `install`, copy [api/.env.example](api/.env.example) to `api/.env` and fill in all required values (database credentials, JWT secret, etc.). Installation will not work correctly with a missing or incomplete `.env` file.

## 1. Overview

A full-featured end-to-end encrypted chat application with private and group conversations, media sharing, and a Telegram-like UI. All encryption and decryption happens exclusively in the browser — the server stores and transmits ciphertext only and can never read messages or media.

---

## 2. Technology Stack

| Layer | Choice |
|---|---|
| Backend | Pure PHP (no framework), front controller pattern |
| Frontend | Vue 3 + Vite + Tailwind CSS |
| Database | MySQL |
| Deployment | Shared hosting (cPanel/DirectAdmin), Apache + `.htaccess` |
| State management | Pinia |
| Crypto | Browser WebCrypto API |

---
 

## 3. Authentication & Key Management

### Registration / Login (same page)

1. User enters email + password (+ name if new).
2. Client calls `POST /auth/check` with email.
3. **Existing user → Login:** Client sends `POST /auth/login`. Server verifies bcrypt, returns JWT. Client must already have the private key file imported in localStorage.
4. **New user → Register:**
   - Browser generates RSA-2048 key pair via WebCrypto API.
   - Private key is exported as a PKCS#8 PEM file and **immediately downloaded** to the user's device. A modal warns: *"Save this file — without it you cannot read your messages on a new device."*
   - Public key PEM + credentials sent to `POST /auth/register`. Server stores public key + bcrypt hash, returns JWT.
5. JWT stored in localStorage, sent as `Authorization: Bearer <token>` on all subsequent requests.
6. **Logout:** Client calls `POST /auth/logout`; server adds JWT JTI to `sessions` blocklist. Client clears JWT + private key from localStorage.

### Importing private key on a new browser

The Auth page shows an **"Import key file"** button below the Continue button. User selects their `.pem` file; the private key is loaded into localStorage. They then log in normally.

### Key loss

If the user loses their private key file and has no browser with it in localStorage, all their message history is permanently unreadable. This is by design.

---

## 4. Encryption Design

### Private messages

1. Sender fetches recipient's RSA public key from `/users/search?email=`.
2. Browser generates a random AES-256-GCM key.
3. Message text encrypted with AES key.
4. AES key encrypted **twice**:
   - With recipient's RSA public key → `encrypted_key_recipient`
   - With sender's own RSA public key → `encrypted_key_sender` (so sender can read own sent messages)
5. Both encrypted key blobs + ciphertext stored in `messages` table.

**Decryption:** Recipient decrypts `encrypted_key_recipient` with their RSA private key → uses AES key to decrypt message content.

### Group messages

1. On group creation, creator generates a random AES-256-GCM group key.
2. For each member (including creator), group key is encrypted with that member's RSA public key → stored in `group_members.encrypted_group_key`.
3. All group messages encrypted once with the group AES key. Single ciphertext stored.
4. **Adding a member:** The adder decrypts the group key from their own `group_members` row (using their RSA private key in localStorage), fetches the new member's RSA public key, re-encrypts the group key with it, posts the new `group_members` row.

### File encryption

Every uploaded file (image, video, voice) is:
1. Encrypted in the browser with a random AES-256-GCM key before upload.
2. The encrypted blob is uploaded to the server via `POST /media/upload`.
3. The file AES key is distributed the same way as message keys (RSA-wrapped for private chats; for groups, the file is encrypted with the group AES key directly).

---

## 5. API Endpoints

All endpoints under `/api/`. All requests and responses are JSON unless noted. JWT required on all endpoints except auth.

```
# Auth
POST   /auth/check              { email } → { exists: bool }
POST   /auth/register           { email, name, password, public_key } → { token }
POST   /auth/login              { email, password } → { token }
POST   /auth/logout             → 200

# Users
GET    /users/search?email=     → { id, name, public_key }

# Polling (single endpoint for all chats)
GET    /updates?since=<iso_timestamp>
  → {
      conversations: [{ id, unread_count, last_message_id, messages: [...] }],
      groups:        [{ id, unread_count, last_message_id, messages: [...] }]
    }

# Private conversations
GET    /conversations                        → list of conversations with unread counts
POST   /conversations                        { email } → { conversation_id }
PATCH  /conversations/:id/read              { last_message_id } → 200  (mark as read)

# Groups
GET    /groups                               → list of groups with unread counts
POST   /groups                               { name, members: [{ email, encrypted_group_key }], encrypted_group_key_self } → { group_id }
GET    /groups/:id/members                   → [{ user_id, name, email, public_key }]
POST   /groups/:id/members                   { email, encrypted_group_key } → 200
PATCH  /groups/:id/read                      { last_message_id } → 200

# Messages
POST   /conversations/:id/messages           { encrypted_content, encrypted_key_recipient, encrypted_key_sender, message_type, media_ids[] } → { message_id }
POST   /groups/:id/messages                  { encrypted_content, message_type, media_ids[] } → { message_id }

# Media
POST   /media/upload                         multipart: encrypted file blob → { file_id, file_path }
GET    /media/:file_id                       → encrypted file blob (auth required)

# Reactions
POST   /messages/:id/reactions               { emoji } → 200
DELETE /messages/:id/reactions/:emoji        → 200

# Settings
GET    /settings                             → { media_cache_days: int }
PUT    /settings                             { media_cache_days: int } → 200
```

---

## 6. Polling & Real-time Updates

- Every **2 seconds**, the client calls `GET /updates?since=<last_poll_timestamp>`.
- Response contains new messages for ALL conversations and groups the user is in.
- Client decrypts each new message using the private key from localStorage.
- Decrypted message text and metadata are stored in Pinia store (in-memory).
- The chat list sidebar updates unread badge counts and last-message previews from the decrypted content.
- When the user opens a conversation, the client calls `PATCH /conversations/:id/read` (or `/groups/:id/read`) with the latest message ID.
- The poll loop continues regardless of which view is active.

---

## 7. Media Caching (IndexedDB)

- After a media file is first downloaded (`GET /media/:file_id`) and decrypted, the **decrypted blob** is stored in IndexedDB keyed by `file_id`.
- On subsequent views, the client checks IndexedDB first and serves the blob directly — no network request.
- A TTL field is stored alongside each cached blob: `{ blob, cached_at }`.
- On app startup, a cleanup routine evicts all entries where `cached_at < now - cache_days`.
- Default TTL: **30 days**. Configurable by the user in Settings (stored in both localStorage and server via `PUT /settings`).

---

## 8. UI & Localisation

### Layout
- Telegram-style: sidebar (chat list) + message thread panel.
- Fully responsive — on mobile, sidebar and thread are separate full-screen views with back navigation.

### Themes
- **Default:** Light mode.
- **Alternate:** Dark mode.
- Toggle button in the chat header (🌙 / ☀️). Preference saved in localStorage.

### Language & direction
- **Default:** Farsi (Persian), RTL layout (`dir="rtl"` on root, Tailwind RTL variants).
- **Alternate:** English, LTR layout.
- FA / EN toggle in the chat header. Preference saved in localStorage.
- Switching language flips the entire layout direction instantly without page reload.
- All UI strings defined in `lib/i18n.js` with `fa` and `en` keys.

### Emoji reactions
Fixed set: 👍 ❤️ 😂 😮 😢 🔥 👏 🎉  
Tapping a message reveals the reaction bar. Tapping a reaction toggles it. Counts shown as pills below the message.

### Media player
- Images: full-screen overlay with pinch-to-zoom on mobile, download button.
- Videos: inline player with controls, download button.
- Voice: inline waveform-style player with play/pause, duration.
- All downloads serve the locally cached decrypted file (not re-downloaded from server).

---

## 9. Security Notes

- Server stores only: RSA public keys, bcrypt password hashes, and ciphertext. It cannot decrypt any message or file.
- Private keys never leave the user's device (except as a downloaded file the user controls).
- JWT JTI blocklist prevents reuse of tokens after logout.
- `uploads/` directory access is denied at the Apache level; files are served only through `MediaController` after JWT validation.
- Uploaded file names are replaced with UUIDs on the server to prevent enumeration.
- CORS headers restricted to the `app/` origin only.


# Chat Bidirectional Pagination & Message Deep Links

**Date:** 2026-05-03  
**Status:** Approved  

---

## Overview

Replace the current all-at-once message load with a 10-message windowed system. Opening a chat lands at the first unread message. Scrolling up loads older history; scrolling down loads newer unread messages. Every message has a shareable URL that opens the chat anchored to that exact message.

---

## 1. Backend API

### Affected endpoints
- `GET /messages` (private conversations) — `MessageController.php` + `Message.php`
- `GET /groups/:id/messages` (group chats) — `GroupController.php` + `Group.php`

### New query parameters

| Param | Meaning | SQL strategy |
|---|---|---|
| `before_id` | 10 messages older than this ID | `WHERE id < ? ORDER BY id DESC LIMIT 11` → reverse |
| `after_id` | 10 messages newer than this ID | `WHERE id > ? ORDER BY id ASC LIMIT 11` |
| `around_id` | 10 before + 10 after this ID | Two queries merged |
| *(none)* | 10 most recent messages | `ORDER BY id DESC LIMIT 11` → reverse |

The existing `since` parameter on `/messages` (used as `id > since`) is superseded by `after_id` and should be removed from the frontend. The `/updates?since=datetime` polling endpoint is a separate system and is untouched.

### Response shape (updated)

```json
{
  "messages": [...],
  "has_more_above": true,
  "has_more_below": false
}
```

`has_more_above` / `has_more_below` are detected by fetching `limit + 1` rows and checking if the extra row exists — no COUNT query needed.

### Initial open with unread messages

The conversation object already contains `last_read_message_id`. The frontend passes `around_id = last_read_message_id + 1` (first unread message). If `last_read_message_id` is null or the conversation is fully read, falls back to no-param (latest 10).

---

## 2. Frontend State (Pinia Stores)

### Affected files
- `app/src/store/chats.js`
- `app/src/store/groups.js`

### State shape

```javascript
messages: {},        // { [convId]: Message[] } — active window only
pagination: {},      // { [convId]: { hasMoreAbove, hasMoreBelow, loadingAbove, loadingBelow, pendingCount } }
scrollPositions: {}, // { [convId]: number } — saved scrollTop per conversation
lruOrder: [],        // [convId, ...] — eviction order, capped at 20 entries
```

`pendingCount` tracks messages received via polling while the user is mid-history (`has_more_below === true`). Shown as a badge on the jump button.

### New actions

| Action | Trigger | Behaviour |
|---|---|---|
| `loadInitial(id)` | Opening a chat | Fetches around first unread, or latest 10 if fully read. Resets window. |
| `loadOlder(id)` | Scroll near top | Prepends 10 using `before_id = messages[id][0].id` |
| `loadNewer(id)` | Scroll near bottom | Appends 10 using `after_id = messages[id].at(-1).id` |
| `loadAroundMessage(id, msgId)` | Deep link open | Fetches `around_id = msgId`, replaces window |

### Cache persistence (Telegram-style)

- Windows are **never cleared on conversation switch** — they persist in memory.
- `scrollPositions[convId]` is saved when leaving, restored on return.
- LRU cap of 20 conversations. When the 21st is opened, the oldest entry is evicted.

### Polling interaction

`applyUpdates()` appends new messages to a cached window **only if** `has_more_below === false`. If the user is mid-history, new messages are counted in a `pendingCount` field on the pagination object instead, shown as a badge on the jump button.

---

## 3. Frontend UI (MessageThread.vue)

### Scroll detection thresholds

- `scrollTop <= 150px` → trigger `loadOlder()` (guarded: no-op if `loadingAbove` or `!hasMoreAbove`)
- `scrollHeight - scrollTop - clientHeight <= 150px` → trigger `loadNewer()` (same guard)
- Both debounced; will not re-fire until the in-flight request completes.

### Scroll anchor when prepending

```javascript
const oldHeight = scrollEl.scrollHeight
await store.loadOlder(convId)
await nextTick()
scrollEl.scrollTop += scrollEl.scrollHeight - oldHeight
```

This prevents the viewport jumping when messages are added above.

### Loading indicators

- Spinner row at the top of the message list while `loadingAbove === true`
- Spinner row at the bottom while `loadingBelow === true`

### "↓ Jump to latest" button

Shown when returning to a cached conversation where `has_more_below === true`. Displays `pendingCount` badge. Tapping calls `loadInitial()` which re-fetches the live end and scrolls to bottom. Reuses the existing scroll-to-bottom FAB component.

### Message element IDs

Each message `<div>` gets `id="msg-{messageId}"` for scroll targeting.

### Deep link mount sequence

```
onMounted:
  if route.params.messageId
    → loadAroundMessage(convId, messageId)
    → nextTick → scrollIntoView('#msg-{messageId}')
    → apply 1-second yellow highlight CSS class
  else
    → loadInitial(convId)
```

---

## 4. Message URLs & Deep Links

### URL format

```
Private:  https://imen.chat/#/chat/c/{conversationId}/m/{messageId}
Group:    https://imen.chat/#/chat/g/{groupId}/m/{messageId}
```

Conversation/group ID is included to avoid a resolution round-trip.

### Vue Router routes (additions)

```javascript
{ path: '/chat/c/:convId/m/:messageId', component: Chat },
{ path: '/chat/g/:groupId/m/:messageId', component: Chat },
// existing routes unchanged:
{ path: '/chat/c/:convId', component: Chat },
{ path: '/chat/g/:groupId', component: Chat },
```

### Copy link UX

New **"Copy link"** item added to the existing `MessagePopup.vue` long-press menu (alongside reply/edit/delete). Writes the full URL to clipboard via `navigator.clipboard.writeText()`.

### Opening a shared link

1. Vue Router matches the route and passes `convId`/`groupId` + `messageId` as params.
2. Chat.vue opens the correct sidebar entry.
3. `loadAroundMessage()` is called instead of `loadInitial()`.
4. After render: `scrollIntoView` + 1-second yellow highlight on `#msg-{messageId}`.

No new backend endpoint is needed — `around_id` on existing endpoints handles the fetch.

---
 