---
paths:
  - "src/**/*.php"
  - "tests/**/*.php"
---

# Coding Standards

- `declare(strict_types=1);` in every .php file
- All HTTP responses must be mapped through DTOs
- All API errors wrapped in Support Exceptions
- Log all API calls via Laravel Log
- Retry logic for transient errors (429, 503)
- Full PHPDoc on every public method
- PSR-12 (Pint), PHPStan level 8
- 100% test coverage — every public method must have a corresponding test