---
description: Run quality checks — tests, static analysis, code style
---

# Quality Check

Run all checks in order:

```!
composer test 2>&1 | tail -20
```

```!
composer analyse 2>&1 | tail -20
```

```!
composer format 2>&1 | tail -20
```