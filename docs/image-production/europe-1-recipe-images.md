# Europe 1 — Recipe Image Production Checklist

Checkpoint 10E (curation added in 10E.1). One physical image per
`recipe_key` **concept** — the CZ and EN recipe posts of the same
`recipe_key` share ONE file, never two. This checklist governs the 100
recipe photos for `production-data/europe-1/` + `production-data/europe-1-en/`.

Machine-readable companions: `europe-1-recipe-images.csv`,
`europe-1-recipe-images.json` (100 rows/objects, same `recipe_key` set).

## Current status

- 100 expected images
- TO_CREATE: 100
- READY: 0
- MISSING (duplicate/invalid file already staged): 0
- World Classics ("Světová klasika" / "World Classics") images: 14
- Priority 1 (World Classics — homepage lead content): 14
- Priority 2 (remaining launch recipes): 86

No image file exists anywhere in this repository yet — every row is
`TO_CREATE` by default until a real, validated photo is staged and the
manifest is regenerated (`php tools/generate-image-production-manifest.php`).
Producing the **14 priority-1 (World Classics) images first**
covers every recipe the CZ and EN homepages actually feature — see
`inc/editorial-curation.php` for the full curated list.

## Naming convention

```
{recipe_key}.jpg
```

`recipe_key` is the SAME stable identity `class-json-importer.php` resolves
`related_recipes` by — never the localized title, never the slug, never file
order. One file serves both the `cs-CZ` and `en` post of that `recipe_key`.

## Source image rule

- Minimum source dimensions: **1600 × 900** (16:9).
- This covers the theme's real registered crops without upscaling:
  `atlas-hero` (1600×900, 16:9) and `atlas-card` (640×480, 4:3) — see
  `class-recipe-image-pipeline.php`'s own `REQUIRED_MIN_WIDTH`/
  `REQUIRED_MIN_HEIGHT` docblock (Checkpoint 10C, section 5 audit).
- A source narrower or shorter than 1600×900 is rejected by the importer as
  `invalid_dimensions` — commission photos at or above this size, never
  smaller.

## Allowed formats

- `.jpg` / `.jpeg`
- `.png`
- `.webp`

No SVG. Max 20 MB per source file.

## ALT policy

- `alt_cs` = the recipe's own localized CZ title (`title_cs` in the
  manifest) — never the file name, never a guessed phrase.
- `alt_en` = the recipe's own localized EN title (`title_en` in the
  manifest).
- Dimensions (e.g. "1600x900") must NEVER appear inside ALT text.
- The HTML `title=""` attribute is never used as an SEO/ALT field — ALT and
  `title=""` are two different things and only ALT carries accessibility/SEO
  meaning here.
- One shared image, two ALT values — resolved per-post at render time by
  `atlas_chuti_recipe_image_alt()` (`functions.php`), not by duplicating the
  attachment.

## ZIP naming and contents

Future delivery ZIP: **`europe-1-recipe-images.zip`**

Flat archive, source images only:

```
svickova.jpg
vepro-knedlo-zelo.jpg
...
```

Do NOT include inside the ZIP:
- the CSV or JSON manifest
- a README
- executables of any kind
- thumbnails / resized copies
- a duplicate CZ vs. EN copy of the same photo (one file per `recipe_key`,
  always)

## Verified workflow (Checkpoint 10C tooling — reused, not duplicated)

1. Regenerate this manifest after any content change:
   ```
   php tools/generate-image-production-manifest.php
   ```
2. Once `europe-1-recipe-images.zip` exists, stage it and dry-run first —
   this writes nothing to the Media Library or to any post:
   ```
   wp atlas image-import europe-1-recipe-images.zip --dry-run
   ```
3. Review the dry-run output: `missing_recipe`, `invalid_dimensions`,
   `invalid_format`, `duplicates`, `unmatched_files` must all be fixed
   (re-export/re-crop/rename) before a real import.
4. Only once the dry-run is fully clean, run the real import:
   ```
   wp atlas image-import europe-1-recipe-images.zip
   ```
5. To overwrite a recipe's existing featured image on a later batch, pass
   `--replace` explicitly — never implicit:
   ```
   wp atlas image-import europe-1-recipe-images.zip --replace
   ```

No import of any kind was run as part of Checkpoint 10E — this checklist
only documents the commands for the future asset batch.
