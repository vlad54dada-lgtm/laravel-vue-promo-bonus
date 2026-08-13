# Promo Bonus — тестове завдання «AI-асистований розробник»

Міні-фіча гемблінг-платформи: нарахування бонусу за промокодом на баланс гравця, історія застосувань, скасування помилково нарахованого бонусу.

**Стек:** Laravel 12 (REST API, Sanctum) · Vue 3 + Vite + axios · SQLite · Pest.

Проєкт розроблено в парі з AI-інструментом (Claude Code) — повний лог промптів та ітерацій: [docs/PROMPTS_LOG.md](docs/PROMPTS_LOG.md).

## Швидкий старт

Потрібно: PHP 8.2+ (з `pdo_sqlite`), Composer, Node 18+.

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Відкрити http://localhost:8000. На Windows замість `cp` — `copy`. На запит «create database.sqlite?» від migrate — відповісти Yes. Для розробки фронтенда замість `npm run build` — `npm run dev` у другому терміналі.

## Демо-дані (сідер)

| Що | Значення |
|---|---|
| Гравець | `player@demo.test` / `password` |
| Другий гравець | `other@demo.test` / `password` |
| `WELCOME50` | бонус 50.00, безстроковий |
| `SUMMER100` | бонус 100.00, діє 30 днів |
| `EXPIRED25` | бонус 25.00, **прострочений** (для демо помилки) |

## API

Всі суми — integer у мінорних одиницях (`*_cents`). Помилки: `{message, error_code}`.

| Метод | Шлях | Опис |
|---|---|---|
| POST | `/api/auth/login` | Bearer-токен за email+password |
| GET | `/api/me` | Поточний гравець + баланс |
| POST | `/api/promo/claim` | Тікет 1: нарахувати бонус за кодом (422 формат / 404 `PROMO_NOT_FOUND` / 409 `PROMO_EXPIRED`, `PROMO_ALREADY_USED`) |
| GET | `/api/promo/history` | Тікет 1: історія з пагінацією та фільтром `status=applied\|rejected\|revoked` |
| PATCH | `/api/promo/{claimId}/revoke` | Тікет 2: скасувати нарахування (повторно — 409 `CLAIM_ALREADY_REVOKED`, кошти не списуються вдвічі) |

## Гарантії коректності (акцент ТЗ)

- Гроші — тільки integer-центи; кожна зміна балансу — в транзакції + рядок у append-only ledger (`wallet_transactions`); інваріант `SUM(ledger) == balance` покритий тестами.
- Подвійне нарахування неможливе: лок рядка гравця → locking read перевірки дубля → `UNIQUE(user_id, promo_code_id)` як фінальний рубіж.
- Подвійне списання неможливе: умовний `UPDATE ... WHERE status='applied'` з перевіркою affected rows.
- Гравець визначається виключно з токена; чужі claim'и — 404 без розкриття існування.
- Деталі: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md).

## Тести

```bash
php artisan test
```

Матриця крайніх випадків (C1–C10, H1–H4, R1–R7) — у [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md).

## Структура документації

- [docs/TASK.md](docs/TASK.md) — оригінальне ТЗ
- [docs/PROJECT.md](docs/PROJECT.md) — скоуп, ризики, deliverables
- [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md) — контракт помилок, доменні рішення, матриця тестів
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — схема БД, стратегія конкурентності
- [docs/ROADMAP.md](docs/ROADMAP.md) — етапи та план комітів
- [docs/PROMPTS_LOG.md](docs/PROMPTS_LOG.md) — лог роботи з AI (deliverable)
