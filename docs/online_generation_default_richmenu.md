# v5.5.1 Online Generation Default Rich Menu

## Purpose

For clients that use only online image generation and do not use class reservations, a segmented rich menu is unnecessary.

This update adds a one-click operation that:

1. Creates a single-button LINE rich menu for image generation.
2. Uploads the rich menu image to LINE.
3. Sets it as the LINE Official Account default rich menu.

## Admin Flow

Open:

`/admin/richmenu-segments`

Then use:

`共通メニューを作成してLINEに表示`

This operation does not require manual Rich Menu ID input and does not require per-user sync.

## LINE Behavior

The created menu is set through:

`POST /v2/bot/user/all/richmenu/{richMenuId}`

Because it is the default menu, LINE users should see it after opening the chat screen again.

If it does not appear immediately, restart the LINE app or reopen the chat.

## Notes

- This is intended for online generation-only clients.
- Reservation/calendar/shop/gacha segment menus remain available for clients that need them.
- The setting key `rich_menu_delivery_mode` is set to `default`.
- The created ID is stored in `rich_menu_online_default_id`.
