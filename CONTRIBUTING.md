# Contributing to Brainitive

Thanks for helping! Bug reports, ideas and pull requests are all welcome.

## Before you start

- **Bugs and ideas:** open an issue first (templates are provided), so we can agree on the
  approach before you spend time on code.
- **Security problems:** please do **not** open an issue — see [SECURITY.md](SECURITY.md).
- **Contributor License Agreement:** before your first pull request can be merged, the CLA bot
  asks you to sign the [CLA](CLA.md) by leaving a comment. You keep the copyright on your
  work; the CLA lets us ship Brainitive under its [licence](LICENSE) (Business Source License 1.1,
  each version becoming AGPL-3.0 after four years) and under commercial licences.

## Development setup

Requirements: PHP 8.2+ (8.4 recommended) with the usual Laravel extensions plus `gmp`,
Composer 2, Node 20+.

```bash
cp .env.example .env
composer install
npm ci
php artisan key:generate
php artisan migrate --seed
composer run dev        # app + queue + logs + vite
```

The whiteboard editor is a separate Excalidraw bundle: after changing `excalidraw-build/src`,
run `cd excalidraw-build && npm ci && npm run build` and commit `public/js/excalidraw-dist/`.

## Pull requests

1. Branch off `main` (`feat/…`, `fix/…`, `chore/…`) and keep the change focused.
2. Add or update feature tests (PHPUnit, `tests/Feature/`).
3. Run the checks — all must be green:
   ```bash
   ./vendor/bin/pint --dirty   # PHP style
   npx tsc --noEmit            # TypeScript
   php artisan test            # tests
   npm run build               # frontend build
   ```
4. User-facing change? Add an entry to the changelog in
   `resources/js/components/HelpModal.tsx`.
5. Use conventional commit messages: `feat(boards): …`, `fix(pages): …`.

Architecture, conventions and known pitfalls are documented in [`docs/ai/`](docs/ai/README.md)
— start with `ARCHITECTURE.md` and `CONVENTIONS.md`.

## Code of conduct

Everyone taking part follows our [Code of Conduct](CODE_OF_CONDUCT.md).
