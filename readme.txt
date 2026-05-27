=== Yamandu Native AI Content Creator ===
Contributors: redepiabanha
Tags: ai content, image generator, alt text, seo, accessibility
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create AI post text, images, titles, and alt text in native WordPress workflows for SEO, accessibility, and editorial speed.

== Description ==

Yamandu Native AI Content Creator brings practical AI assistance into the WordPress editorial workflow without forcing publishers, agencies, and site owners into a separate content platform.

The plugin helps teams create prompt-based post text, generate images, and produce useful image titles and alt text directly inside familiar WordPress screens. It is built for editorial operations that care about speed, consistency, accessibility, search visibility, and control over how external AI services are used.

Yamandu combines image understanding from Google Cloud Vision with text and image generation powered by Gemini model workflows. The result is a cleaner content process: editors can create text inside Gutenberg or the Classic Editor, generate new images for the Media Library, and improve attachment metadata from native WordPress interfaces.

= A native workflow for serious content teams =

Yamandu is designed for websites where publishing efficiency matters, but quality and governance cannot be sacrificed. Instead of adding a heavy dashboard or proprietary content layer, it works with WordPress posts, media attachments, metadata fields, row actions, bulk actions, and settings screens.

This makes the plugin especially useful for:

* Publishers that need a faster editorial workflow.
* Agencies managing content production for multiple websites.
* Marketing teams that want consistent image metadata and AI-assisted drafting.
* Site owners who need better media titles and alt text without manual repetition.
* WordPress administrators who want explicit control over API keys, models, consent, and overwrite behavior.

= Core advantages =

* Native WordPress integration: generate content where editors already work.
* Gutenberg and Classic Editor support for prompt-based post text generation.
* Media Library integration for AI image generation and metadata workflows.
* Attachment-level actions for individual image titles and alt text.
* Row actions and bulk actions for efficient media operations.
* Intentional regeneration controls to avoid accidental replacement of existing metadata.
* Configurable overwrite behavior for administrators who need stricter control.
* Google API key ownership remains with the site administrator.
* External requests remain disabled until administrator consent is enabled.
* Generated metadata is stored in native WordPress fields, not in a proprietary content layer.
* Focused SEO and accessibility benefits without turning the plugin into a bloated SEO suite.

= What the plugin can generate =

* Post text from editorial prompts inside the WordPress editor.
* AI-generated images saved directly to the WordPress Media Library.
* Image attachment titles designed for cleaner media organization.
* Image alt text written to support accessibility and image SEO.

= Editorial highlights =

* Generate content without reloading the editor.
* Insert generated text into the current post workflow.
* Create images from written prompts in the WordPress dashboard.
* Generate metadata for one image at a time from the attachment screen.
* Process selected images through Media Library bulk actions.
* Regenerate eligible fields when an intentional replacement is needed.
* Validate the Google API key from the plugin settings screen.
* Choose supported Gemini text models and image generation model options.
* Enable or disable third-party processing through an explicit consent setting.
* Choose whether plugin settings and cached data should be removed on uninstall.

= Built for control, not blind automation =

Yamandu does not treat AI generation as an invisible background process. Administrators decide when external requests are allowed, which API key is used, which model options are selected, which fields are eligible for generation, and whether existing metadata may be overwritten.

This approach is important for professional publishing environments. It helps teams improve speed while preserving editorial judgment, compliance awareness, and control over the final content that appears on the site.

= Supported model families =

Yamandu includes settings for Gemini text generation and image generation workflows, including Gemini, Nano Banana, and Imagen 4 model options as available in the plugin settings.

== Installation ==

1. Upload the `yamandu-native-ai-content-creator` folder to `/wp-content/plugins/` or install the ZIP through the WordPress admin.
2. Activate the plugin from the Plugins screen.
3. Open `Settings -> Yamandu`.
4. Enter your Google API key.
5. Review the data handling notice and enable third-party requests.
6. Validate the API key.
7. Select the preferred text and image generation model options.
8. Save the settings.

== External Services ==

This plugin connects to external Google services only after an administrator explicitly enables third-party requests and provides a Google API key.

Services used by the plugin:

* Cloud Vision API: https://cloud.google.com/vision
* Gemini API / Generative Language API: https://ai.google.dev/
* Google Privacy Policy: https://policies.google.com/privacy

Data sent to external services may include selected image file content, image URLs or file data when required by the chosen workflow, existing image metadata, OCR text, labels, logos, web entities, site language information, generation settings, text prompts, and optional selected post text when the editor text generator is used.

Requests are made only for administrator-triggered generation, Media Library bulk operations, or image creation actions initiated through the plugin.

Important setup notes:

* Activate billing for the Google Cloud project and enable the Cloud Vision API.
* Enable the Generative Language API in the same project.
* When possible, restrict the API key to Cloud Vision API and Generative Language API.

== Frequently Asked Questions ==

= What does Yamandu Native AI Content Creator do? =

Yamandu helps WordPress teams generate post text, create images, and produce image titles and alt text from native WordPress workflows.

= Does the plugin work with Gutenberg? =

Yes. Yamandu includes prompt-based post text generation for Gutenberg and also supports the Classic Editor workflow.

= Can it generate images inside WordPress? =

Yes. The plugin can create images from prompts and save the generated files directly to the WordPress Media Library.

= Can it generate alt text for existing images? =

Yes. Yamandu can generate image alt text for WordPress image attachments to support accessibility and image SEO.

= Can it generate media titles? =

Yes. The plugin can generate attachment titles to improve media organization and metadata quality.

= Does it support bulk processing? =

Yes. Selected images can be processed through Media Library bulk actions, which helps teams improve metadata across multiple attachments more efficiently.

= Will Yamandu overwrite existing metadata? =

Standard generation can be configured to respect existing fields. Regeneration actions are intentional replacement actions and may overwrite eligible fields.

= Does the plugin send data to external services? =

Yes, but only after an administrator enables third-party requests in the plugin settings. Requests may include selected image content, image URLs or file data, existing metadata, OCR text, labels, logos, web entities, site language information, generation settings, prompts, and optional selected post text.

= Which Google services are used? =

The plugin uses Google Cloud Vision for image analysis and Gemini API / Generative Language API for text, metadata, and image generation workflows.

= Does Yamandu replace a full SEO plugin? =

No. Yamandu focuses on AI-assisted content creation, image generation, image titles, and alt text. It is designed to complement a professional WordPress publishing stack rather than replace broader SEO, schema, analytics, or technical optimization tools.

= What happens when the plugin is uninstalled? =

By default, uninstall preserves plugin data. Administrators can enable data removal on uninstall in the plugin settings.

== Changelog ==

= 1.0.0 =
* Initial release.
