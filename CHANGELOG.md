# CHANGELOG

This changelog documents updates implemented in the forked repository: [Bagisto REST API](https://github.com/bagisto/rest-api).
These updates have been applied to the forked REST API.

## v1.0.6 (August 10, 2026) - Release

- Attribute & Family Lookup: Return `404` for an unknown code instead of a `500` fatal error.
- Bulk Product Payloads: Reject an empty payload with `422` and report the `skipped` and `queued` SKUs.
- Compatibility: Declared PHP `^8.3` and Laravel `^12.0`.

## v1.0.5 (June 24, 2026) - Release

- Compatibility: Added support for Bagisto 2.4.x and the UnoPim Connector v2.1.x.

## v1.0.4 (March 10, 2025) - Release

- Bulk Product API: Updated the endpoint and applied Laravel Pint code style.

## v1.0.3 (February 26, 2025) - Release

- Attribute Families: Added the family default attribute on create and update.

## v1.0.2 (February 5, 2025) - Release

- Lookup by Code: Added support for fetching an attribute and an attribute family by code.

## v1.0.1 (January 30, 2025) - Release
#### Improvements  
- **Category Import**: Enhanced ID-based processing and implemented batch retry handling for more reliable imports.  

#### New Features  
- **S3 Compatibility**: Added support for both S3 protocol and S3 URLs within the same bucket to ensure seamless integration.

## v1.0.0 (January 20, 2025) - Release

- Locale Retrieval: Introduced functionality to fetch all locales associated with a channel.
- Bulk Product Creation: Added a bulk product creation API powered by a job system for efficient processing.
