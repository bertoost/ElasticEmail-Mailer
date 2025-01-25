# Elastic Email Symfony Mailer Changelog

## v1.1.1 - 2025-01-25

### Changed

- Fix PHP 8.4 deprecation ([#2](https://github.com/bertoost/ElasticEmail-Mailer/pull/2))

## v1.1.0 - 2024-04-14

### Changed

- Transformed mailer bridge into a full Symfony bundle
- Added service configuration to tag the transport
- Tested and support MAILER_DSN from Symfony's Mailer component correctly ([#1](https://github.com/bertoost/ElasticEmail-Mailer/issues/1))

## v1.0.3 - 2023-02-20

### Changed

- Fixed transport API build email payload correctly  
  _Non-empty attachments and headers_

## v1.0.2 - 2023-02-19

### Changed

- Fixed sending API Transport calls over https instead of none

## v1.0.1 - 2022-09-25

### Changed

- Fixed capturing empty reply-to

## v1.0.0 - 2022-09-21

### Added

- Added initial sources