# Aucor Download and Replace Attachments

**Contributors:** [Teemu Suoranta](https://github.com/TeemuSuoranta)

**Tags:** WordPress, media, attachments, wp-cli

**License:** GPLv2 or later

## Description

Download and replace attachments (img and a tags) in WordPress content via WP-CLI. Adds attachments to WordPress Media Library.

### When to use

 * Importing content that has images (that can't be automatically imported via WordPress importer)

### How to use

Basic (all post types, image size large):

`wp aucor-download-and-replace-attachments run`

Restrict post type:

`wp aucor-download-and-replace-attachments run --post_type=page`

Restrict domain to import from:

`wp aucor-download-and-replace-attachments run --from_domain=aucor.fi`

Restrict file extensions:

`wp aucor-download-and-replace-attachments run --extensions=jpg,png`

Change target image size:

`wp aucor-download-and-replace-attachments run --image_size=large`

### Output

```
$ wp aucor-download-and-replace-attachments run
Replacing "https://starter.aucor.fi/image.jpg" with new attachment #1605 (https://www.aucor.fi/wp-content/uploads/2019/10/image.jpg) on post #512
Replacing "https://starter.aucor.fi/image3.jpg" with new attachment #1606 (https://www.aucor.fi/wp-content/uploads/2019/10/image3.jpg) on post #514
Warning: No replacement for attachment "https://markkinointiakatemia.fi/test.pdf" on post #659
Success: Done: 2 posts modified, 20 posts ignored.
```

## Disclaimer

You should backup your database and uploads before running this. This plugin comes with no warranty or promises to not set your website on fire. Thread carefully.

### Known bugs

* If img tags already have "wp-image-123" and "size-large" classes, this plugin will just add another ones
* The "size-large" does not take account the real image size, it just outputs whatever was the target image size
* If you are using Polylang / WPML the new attachments are not added to proper language
* Extension detection can't handle multi-part extension (like .tar.gz)

### Feature whislist

* Optionally save attachments outside of media library like `/uploads/legacy/`
