# ROADMAP — етапи розробки та план комітів

ТЗ вимагає **реальну історію комітів**. Кожен етап = 1–3 осмислені коміти. Порядок: бекенд з тестами → фронтенд, по тікету за раз (ТЗ явно каже: Тікет 2 — після Тікета 1).

Часовий бюджет: ~4–6 год загалом.

## Етап −1 — Передумови середовища (разово)

На машині є Node 24 + npm, але **немає PHP і Composer**. Установка (winget):

```
winget install PHP.PHP.8.4
winget install Composer.Composer
```

Після установки: створити `php.ini` з `php.ini-production` і увімкнути розширення, потрібні Laravel: `curl, fileinfo, mbstring, openssl, pdo_sqlite, sqlite3, zip`. Перевірка: `php -m` містить `pdo_sqlite`; `composer -V` працює.

## Етап 0 — Ініціалізація (~30 хв)

- [ ] `git init`, перший коміт: `docs/` (планування до коду — частина історії)
- [ ] Laravel 12 скелет: `composer create-project laravel/laravel:^12.0 tmp` → перенести в корінь (корінь непорожній через docs/)
- [ ] `.env`: SQLite (`database/database.sqlite`)
- [ ] Sanctum: `php artisan install:api`
- [ ] Pest: `composer require pestphp/pest-plugin-laravel --dev` + `php artisan pest:install`
- [ ] Коміт: `chore: laravel 12 skeleton + sanctum + pest`

**DoD:** `php artisan serve` віддає стартову сторінку; `php artisan test` зелений.

## Етап 1 — Схема БД і сідери (~40 хв)

- [ ] Міграції: `balance_cents` у users; `promo_codes`; `promo_claims` (з UNIQUE(user_id, promo_code_id)); `wallet_transactions`
- [ ] Моделі з касти/зв'язками
- [ ] Сідери з [ARCHITECTURE.md](ARCHITECTURE.md) (демо-гравці + 3 промокоди)
- [ ] Коміт: `feat: db schema — players balance, promo codes, claims, wallet ledger`

**DoD:** `php artisan migrate:fresh --seed` без помилок; у БД видно демо-дані.

## Етап 2 — Auth для демо (~20 хв)

- [ ] `POST /api/auth/login` → токен Sanctum; `GET /api/me` → user + balance_cents
- [ ] Коміт: `feat: token auth (login, me)`

**DoD:** curl'ом можна залогінитись і отримати себе з балансом.

## Етап 3 — Тікет 1, backend (~1 год – 1 год 20 хв) ← ядро завдання

- [ ] `ClaimPromoRequest` (422-валідація формату)
- [ ] Доменні винятки + обробник → `{message, error_code}` з правильними HTTP-кодами
- [ ] `PromoService::claim` — транзакція, локи, rejected-записи, ledger (див. [ARCHITECTURE.md](ARCHITECTURE.md))
- [ ] `POST /api/promo/claim` + throttle
- [ ] `GET /api/promo/history` — пагінація, фільтр, тільки свої
- [ ] Pest-тести: C1–C10, H1–H4 з матриці [REQUIREMENTS.md](REQUIREMENTS.md)
- [ ] Коміти: `feat: promo claim endpoint …`, `feat: promo history …`, `test: claim edge cases …`

**DoD:** всі тести матриці Claim/History зелені; повторний claim і паралельна гонка не дають подвійного нарахування; `SUM(ledger) == balance`.

## Етап 4 — Тікет 1, frontend (~1 год)

- [ ] Vite + Vue 3 + axios (`api.js` з Bearer-interceptor)
- [ ] LoginForm (демо-креденшли підказані на екрані)
- [ ] Шапка з балансом
- [ ] PromoClaimForm: idle/loading/success/error, текст причини з API
- [ ] PromoHistory: список (дата, код, сума, статус-бейдж), фільтр-таби, пагінація
- [ ] Оновлення балансу та історії після claim
- [ ] Коміти: `feat: vue scaffold + auth flow`, `feat: promo claim form + history UI`

**DoD:** у браузері повний happy-path і всі три типи помилок видно з людським текстом.

## Етап 5 — Тікет 2, backend (~40 хв)

- [ ] `PromoService::revoke` — транзакція, лок claim, перевірка статусу, списання, ledger
- [ ] `PATCH /api/promo/{claimId}/revoke`
- [ ] Pest-тести: R1–R7
- [ ] Коміти: `feat: revoke promo claim …`, `test: revoke idempotency & isolation`

**DoD:** повторний revoke → 409 і баланс незмінний; чужий claim → 404; тести зелені.

## Етап 6 — Тікет 2, frontend (~30 хв)

- [ ] Кнопка «Скасувати» біля applied-рядків, confirm-діалог, per-row loading
- [ ] Оновлення статусу рядка й балансу після відповіді
- [ ] Показ помилки, якщо вже скасовано (і рефреш списку)
- [ ] Коміт: `feat: revoke button with confirmation in history`

**DoD:** сценарій «нарахував → скасував → бачу revoked і зменшений баланс» працює в браузері.

## Етап 7 — Полірування і здача (~40 хв)

- [ ] README: вимоги, setup ≤5 команд, демо-креденшли, скріншот
- [ ] Фінальна вичитка [PROMPTS_LOG.md](PROMPTS_LOG.md) (він ведеться весь час, тут — тільки вичитка)
- [ ] [CODE_REVIEW.md](CODE_REVIEW.md) — фінальна вичитка
- [ ] Прогін повного тест-сьюта, ручний прогін демо-сценарію
- [ ] Push на GitHub, перевірка що історія комітів читається як історія
- [ ] Запис відео (сценарій нижче)

## Етап 8 — Кулдаун 24 години на claim (нова фіча після здачі)

Правило D7 з [REQUIREMENTS.md](REQUIREMENTS.md): один успішний claim на 24 години на гравця, незалежно від коду. Docs-first: спершу оновлені REQUIREMENTS/ARCHITECTURE/ROADMAP/PROMPTS_LOG, потім код.

- [ ] Коміт `docs:` — правило D7, контракт `PROMO_COOLDOWN`, матриця C11–C15, стратегія конкурентності
- [ ] `config/promo.php` (`cooldown_hours`, env `PROMO_COOLDOWN_HOURS`, дефолт 24)
- [ ] `PromoRejectReason::Cooldown`, `PromoCooldownException` (409, `next_claim_available_at` у JSON)
- [ ] `PromoService::claim` — перевірка кулдауну locking read'ом у наявній транзакції, після перевірки already_used
- [ ] Pest-тести C11–C15 + пріоритет `PROMO_ALREADY_USED` над кулдауном; правка тесту ledger (два claim'и тепер потребують time travel)
- [ ] Frontend: людське повідомлення з локальним часом наступної спроби у формі claim; підпис причини `cooldown` в історії
- [ ] Коміт `feat:` (backend + тести + frontend + PROMPTS_LOG)

**DoD:** тести зелені; другий код у межах 24 год → 409 з часом наступної спроби на формі; через time travel — 200. **Увага для майбутніх демо:** сценарій відео нижче писався до кулдауну — кроки з двома успішними claim підряд тепер вимагають або два акаунти, або зміну `PROMO_COOLDOWN_HOURS=0`.

## Сценарій демо-відео (2–5 хв)

1. `php artisan migrate:fresh --seed`, `npm run dev`, `php artisan serve` — показати запуск.
2. Логін демо-гравцем. Баланс 0.
3. Claim `WELCOME50` → успіх, баланс 50.00, запис в історії.
4. Claim `WELCOME50` вдруге → помилка «вже використаний», rejected-запис в історії.
5. Claim `EXPIRED25` → помилка «прострочений».
6. Claim `abc` → 422 «невірний формат» (клієнт/сервер).
7. Фільтр історії: Applied / Rejected.
8. Revoke успішного нарахування: confirm → статус revoked, баланс 0.
9. Спроба revoke ще раз (кнопки вже нема; показати через повторний запит або другу вкладку) → помилка, баланс не змінився.
10. (Опційно) Показати зелений `php artisan test`.

## Правила ведення історії комітів

- Коміт = одна логічна одиниця, повідомлення в conventional-стилі (`feat:`, `test:`, `chore:`, `docs:`).
- Ніяких «wip»/«fix2». Тести комітяться разом з фічею або одразу після.
- PROMPTS_LOG оновлюється в тому ж коміті, що й код, який він описує (видно зв'язок промпт → код).
