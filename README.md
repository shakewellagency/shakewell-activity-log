# Shakewell Activity Log

Free GPL-2.0-or-later WordPress plugin. See `readme.txt` for what it logs and how to use it.

```sh
composer install && npm ci
npm run env:start      # WordPress at http://localhost:8898, tests on 8899 (needs Docker)
npm test               # PHPUnit in wp-env
composer lint          # WordPress coding standards
npm run plugin-check   # official Plugin Check
```

Release steps and naming rules: see the Shakewell plugin template README.
