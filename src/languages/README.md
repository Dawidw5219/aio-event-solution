# Internationalization (i18n)

This directory contains translation files for the All-in-One Event Solution plugin.

## File Structure

- `aio-event-solution.pot` - Template file (source of all translations)
- `aio-event-solution-pl_PL.po` - Polish translations
- `aio-event-solution-pl_PL.mo` - Compiled Polish translations (binary)
- `aio-event-solution-fr_FR.po` - French translations (to be added)
- `aio-event-solution-it_IT.po` - Italian translations (to be added)
- `aio-event-solution-es_ES.po` - Spanish translations (to be added)
- `aio-event-solution-de_DE.po` - German translations (to be added)

## Prerequisites

To generate translation files, you need PHP installed on your system.

### Install PHP (macOS):
```bash
brew install php
```

### Verify PHP installation:
```bash
php --version
```

## Generating Translation Files

### Generate .pot template:
```bash
pnpm i18n:pot
```

**Note:** This command requires PHP to be installed and available in your PATH.

### Compile translations:
```bash
# Compiles all .po files found in src/languages/ directory
pnpm i18n:compile
```

This command automatically finds all `.po` files and compiles them to `.mo` files.

## Adding New Translations

1. **Generate/update `.pot` file**: `pnpm i18n:pot` (requires PHP)
2. **Copy `.pot` to `.po`** for your language:
   ```bash
   cp src/languages/aio-event-solution.pot src/languages/aio-event-solution-pl_PL.po
   ```
3. **Edit `.po` file** and add translations:
   - Update header with language info (Language: pl_PL, etc.)
   - Add Polish translations in `msgstr ""` fields
4. **Compile to `.mo`**: `pnpm i18n:compile` (requires msgfmt/gettext)
   - This automatically compiles all `.po` files found in `src/languages/`

## Current Status

- ✅ All code strings are in English (default language)
- ✅ Translation files created for:
  - Polish (pl_PL) - basic translations added
  - French (fr_FR) - basic translations added
  - Italian (it_IT) - basic translations added
  - Spanish (es_ES) - basic translations added
  - German (de_DE) - basic translations added
- ⚠️ Full translations need to be completed for all languages

## Text Domain

All strings use the text domain: `aio-event-solution`

