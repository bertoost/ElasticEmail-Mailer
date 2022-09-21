<div style="text-align: center;">
    <img src="docs/logo.svg" style="width:25%;" alt="Elastic Email">
</div>

# Elastic Email Mailer for Symfony Mailer

Provides Elastic Email integration for Symfony Mailer.

## Installation

```bash
composer require bertoost/elasticemail-mailer
```

## Usage

Symfony Mailer DSN

```env
MAILER_DSN=elasticemail+api://$API_KEY@default
```

Initialize manually:

```php
use bertoost\Mailer\ElasticEmail\Transport\ElasticEmailApiTransport;

$transport = new ElasticEmailApiTransport('YOUR_API_KEY');
```