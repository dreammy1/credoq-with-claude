Translation files for credoq-engine.

Generate a .pot file with WP-CLI from the plugin root:
  wp i18n make-pot . languages/credoq-engine.pot --domain=credoq-engine

Then translate with Poedit (or any gettext editor) into
languages/credoq-engine-{locale}.po / .mo, e.g. languages/credoq-engine-fr_FR.po
