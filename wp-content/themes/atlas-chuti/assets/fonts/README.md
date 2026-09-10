# Self-hosting the fonts

By default the theme loads Newsreader + Work Sans from Google Fonts (see
`atlas_chuti_enqueue_fonts()` in `functions.php`). For production, self-hosting
avoids the extra external request (item 5 of the brief):

1. Download the woff2 files for the weights actually used:
   - Newsreader: 400, 500, 600, 700 (and the optical-size axis if you keep the
     variable font)
   - Work Sans: 400, 500, 600, 700
   Both are licensed under the SIL Open Font License, so self-hosting is fine.
   Get them from [Google Fonts](https://fonts.google.com/) ("Download family") or
   [Fontsource](https://fontsource.org/fonts/newsreader) for ready-made static
   woff2 files.
2. Put the `.woff2` files in this folder.
3. Add a `fonts.css` file here with `@font-face` rules pointing at them, e.g.:

   ```css
   @font-face {
     font-family: 'Newsreader';
     font-style: normal;
     font-weight: 400 700;
     font-display: swap;
     src: url('newsreader-variable.woff2') format('woff2');
   }
   @font-face {
     font-family: 'Work Sans';
     font-style: normal;
     font-weight: 400 700;
     font-display: swap;
     src: url('work-sans-variable.woff2') format('woff2');
   }
   ```

As soon as `assets/fonts/fonts.css` exists, the theme automatically switches
from the Google Fonts CDN to this local stylesheet — no code changes needed.
