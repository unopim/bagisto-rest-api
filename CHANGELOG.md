# CHANGELOG

This changelog documents updates implemented in the forked repository: [Bagisto REST API](https://github.com/bagisto/rest-api).
These updates have been applied to the forked REST API.
## v1.0.5 (June 24, 2026) - Release

#### Update
- Compatibility with Bagisto 2.4.x and the UnoPim v2.1.x connector.

#### Fixed
- Exception handler method visibility changed from `private` to `protected` for compatibility with Bagisto 2.4.x.
- Product image import: only use the S3 disk when AWS credentials are configured (an empty key no longer triggers the uninstalled S3 driver), and update image encoding to the Intervention Image v3 API.
- Configurable variants: resolve variant SKUs from storage and skip missing ones so the bulk import no longer fails on an unresolved variant.

## v1.0.4 (March 10, 2025) - Release

#### Improvements
- **Bulk Product API**: Added a dynamic validator that enforces `required` and `unique` rules per attribute (driven by the attribute configuration) for bulk product creation.
- **Configurable Variants**: Improved `prepareConfigurableVariants` parsing of variant data during bulk import.

#### Update
- Standardized code style across resources, routes, and language files with Laravel Pint.

## v1.0.3 (February 26, 2025) - Release

#### New Features
- **Family Default Attributes**: Added default attribute handling for attribute families on create and update.
- **Payload Resources**: Introduced payload resources for Attribute, Attribute Group, and Attribute Family responses.

## v1.0.2 (February 5, 2025) - Release

#### New Features
- **Fetch by Code**: Added endpoints to fetch an attribute and an attribute family by their `code`.

#### Improvements
- **Product Import**: Trigger Elasticsearch, inventory, and price indexers after product creation so newly imported products are searchable immediately.
- **Product Import**: Hardened image handling from URLs with error logging when a download or file write fails.

## v1.0.1 (January 30, 2025) - Release
#### Improvements
- **Category Import**: Enhanced ID-based processing and implemented batch retry handling for more reliable imports.

#### New Features
- **S3 Compatibility**: Added support for both S3 protocol and S3 URLs within the same bucket to ensure seamless integration.

## v1.0.0 (January 20, 2025) - Release

- Locale Retrieval: Introduced functionality to fetch all locales associated with a channel.
- Bulk Product Creation: Added a bulk product creation API powered by a job system for efficient processing.
