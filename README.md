# money-management-tool

**PartnerLedger** — a front-end **DEMO** of a Multi-Partner Business Finance Management System.

> ⚠️ DEMO / SAMPLE DATA ONLY. All partners, GST numbers, bank accounts and orders are fictional.
> No backend, no real bank, GST portal or marketplace integration. Not for accounting or tax filing.

## How to run

Open `index.html` in any modern browser. That's it — no server, no install.

Charts (Chart.js) and icons (Lucide) load from a CDN, so an internet connection is needed for those; everything else works offline.

## Files

| File | Purpose |
|------|---------|
| `index.html` | App shell: sidebar, top bar, containers |
| `style.css` | All styling (responsive desktop / tablet / mobile) |
| `data.js` | Fictional seed data (5 partners, 5 bank accounts, 4 GST registrations, 3 platforms, 10 products, 15 purchases, 20 sales, 5 returns, 15 expenses, 5 settlements) |
| `script.js` | App logic: routing, tables, forms, calculations, charts, CSV export, LocalStorage |

## Features

- 16 pages: Dashboard, Partners, Partner Investments, GST Registrations, Bank Accounts, Bank Transfers, Selling Platforms, Products & Inventory, Purchases, Sales, Returns & Refunds, Expenses, Settlements, Profit & Loss, Financial Reports, Settings
- Add / edit / delete records with validation (stock, bank balance, profit-share totals)
- Search, sort, filter and paginate every table; export any table to CSV
- Bank balances, stock, partner capital, settlements and P&L recalculate automatically
- Internal transfers are never counted as income or expenses
- P&L recognises cost of goods sold, not inventory purchases
- Data persists in the browser's LocalStorage; **Settings → Reset to sample data** restores the demo
