/**
 * MoneyApp v2.0 — JavaScript principal (sem framework).
 * Cada página PHP renderiza o header e um <div id="page-content">.
 * Este script detecta a página (por data-page do body) e renderiza a view.
 */

document.addEventListener('DOMContentLoaded', () => {
    const page = document.body.getAttribute('data-page');
    const actionsEl = document.getElementById('page-actions');
    const contentEl = document.getElementById('page-content');

    // Chama init automaticamente para garantir banco populado na primeira visita.
    api('/api/init.php', { method: 'POST' }).catch(() => {});

    const pages = {
        dashboard: () => DashboardView(contentEl, actionsEl),
        transactions: () => TransactionsView(contentEl, actionsEl),
        accounts: () => AccountsView(contentEl, actionsEl),
        categories: () => CategoriesView(contentEl, actionsEl),
        payees: () => PayeesView(contentEl, actionsEl),
        institutions: () => InstitutionsView(contentEl, actionsEl),
    };

    const init = pages[page];
    if (init) init();
    else contentEl.innerHTML = '<p>Página desconhecida.</p>';
});

// ============================================================
// DASHBOARD
// ============================================================
async function DashboardView(root, actions) {
    if (!root) {
        console.error('[DashboardView] root (page-content) é null — HTML não renderizou?');
        return;
    }
    if (actions) {
        actions.innerHTML = '';
        const goTx = el('a', {
            className: 'btn btn-primary',
            attrs: { href: '/transactions.php' },
            style: 'padding:10px 18px;font-size:14px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px',
        }, [el('span', { text: '+ Nova transação' })]);
        actions.appendChild(goTx);
    }
    root.innerHTML = '<div class="loading">Carregando dashboard...</div>';

    let summary, trend, categories, accounts, categoryMatrix;
    try {
        [summary, trend, categories, accounts, categoryMatrix] = await Promise.all([
            api('/api/summary.php'),
            api('/api/summary.php?chart=trend'),
            api('/api/summary.php?chart=categories'),
            api('/api/accounts.php'),
            api('/api/summary.php?chart=category_matrix'),
        ]);
    } catch (e) {
        root.innerHTML = `<div class="empty"><p>Erro ao carregar dashboard (API): ${escapeHtml(e.message)}</p></div>`;
        return;
    }

    root.innerHTML = '';
    const monthWord = (summary.month_label || '').split(' ')[0] || 'mês';
    const monthLabelFull = summary.month_label || monthWord;

    // Taxa de sobra = saldo / receita (quando há receita)
    const incomeCents = summary.month_income_cents || 0;
    const balanceCents = summary.month_balance_cents || 0;
    let savingsRate = null;
    if (incomeCents > 0) {
        savingsRate = Math.round((balanceCents / incomeCents) * 100);
    }

    // --- Stats grid ---
    try {
        const netTodayCents = summary.net_worth_today_cents ?? summary.net_worth_cents ?? 0;
        const netFinalCents = summary.net_worth_final_cents ?? summary.net_worth_cents ?? 0;
        const netTodayFmt = summary.net_worth_today_formatted || summary.net_worth_formatted || '—';
        const netFinalFmt = summary.net_worth_final_formatted || summary.net_worth_formatted || '—';
        const assetsToday = summary.total_assets_today_formatted || summary.total_assets_formatted || '—';
        const liabToday = summary.total_liabilities_today_formatted || summary.total_liabilities_formatted || '—';
        const assetsFinal = summary.total_assets_final_formatted || summary.total_assets_formatted || '—';
        const liabFinal = summary.total_liabilities_final_formatted || summary.total_liabilities_formatted || '—';

        const netTodayAccent = netTodayCents >= 0 ? 'income' : 'expense';
        const netFinalAccent = netFinalCents >= 0 ? 'income' : 'expense';
        const balAccent = balanceCents >= 0 ? 'income' : 'expense';
        const savingsSub = savingsRate != null
            ? `${savingsRate}% da receita · ${balanceCents >= 0 ? 'superávit' : 'déficit'}`
            : (balanceCents >= 0 ? 'Superávit do mês' : 'Déficit do mês');

        const stats = el('div', { className: 'stats-grid' }, [
            statCard(
                'Patrimônio · até hoje',
                netTodayFmt,
                netTodayAccent,
                `Ativos ${assetsToday} · Passivos ${liabToday}`,
            ),
            statCard(
                'Patrimônio · final',
                netFinalFmt,
                netFinalAccent,
                `Com futuros · Ativos ${assetsFinal} · Passivos ${liabFinal}`,
            ),
            statCard(
                `Receitas · ${monthWord}`,
                summary.month_income_formatted || '—',
                'income',
                monthLabelFull,
            ),
            statCard(
                `Despesas · ${monthWord}`,
                summary.month_expense_formatted || '—',
                'expense',
                monthLabelFull,
            ),
            statCard(
                'Saldo do mês',
                summary.month_balance_formatted || '—',
                balAccent,
                savingsSub,
            ),
        ]);
        root.appendChild(stats);
    } catch (e) {
        console.error('[Dashboard] stats falhou:', e);
        root.appendChild(el('div', { className: 'empty', text: `Stats: ${e.message}` }));
    }

    // --- Charts row ---
    try {
        const chartsRow = el('div', { className: 'charts-grid' });

        const barCard = el('div', { className: 'card' }, [
            el('div', { className: 'card-header' }, [
                el('div', {}, [
                    el('h3', { className: 'card-title', text: 'Receita × Despesa' }),
                    el('p', { className: 'card-subtitle', text: 'Últimos 6 meses' }),
                ]),
                el('div', { className: 'chart-legend' }, [
                    el('span', { className: 'chart-legend-item' }, [
                        el('span', { className: 'chart-legend-swatch income' }),
                        el('span', { text: 'Receita' }),
                    ]),
                    el('span', { className: 'chart-legend-item' }, [
                        el('span', { className: 'chart-legend-swatch expense' }),
                        el('span', { text: 'Despesa' }),
                    ]),
                ]),
            ]),
            el('div', { className: 'card-body' }, [
                el('canvas', { className: 'chart-canvas', attrs: { id: 'bar-canvas' } }),
            ]),
        ]);
        chartsRow.appendChild(barCard);

        const pieCard = el('div', { className: 'card' }, [
            el('div', { className: 'card-header' }, [
                el('div', {}, [
                    el('h3', { className: 'card-title', text: 'Despesas por categoria' }),
                    el('p', { className: 'card-subtitle', text: monthLabelFull }),
                ]),
            ]),
            el('div', { className: 'card-body card-body-pie' }, [
                el('canvas', { className: 'chart-canvas chart-canvas-pie', attrs: { id: 'pie-canvas' } }),
            ]),
        ]);
        chartsRow.appendChild(pieCard);
        root.appendChild(chartsRow);

        // Legenda da pizza com % 
        const pieBody = pieCard.querySelector('.card-body');
        if (Array.isArray(categories) && categories.length > 0 && pieBody) {
            const totalCat = categories.reduce((s, c) => s + (c.cents || 0), 0) || 1;
            const colors = ['#7dcea0', '#f08080', '#e6b84d', '#8a9bb0', '#c49a6c', '#a0a8b4'];
            const legend = el('ul', { className: 'pie-legend' });
            const maxItems = Math.min(categories.length, 6);
            for (let i = 0; i < maxItems; i++) {
                const c = categories[i] || {};
                const pct = Math.round(((c.cents || 0) / totalCat) * 100);
                legend.appendChild(el('li', { className: 'pie-legend-item' }, [
                    el('span', { className: 'pie-legend-swatch', attrs: { style: `background:${colors[i % colors.length]}` } }),
                    el('span', { className: 'pie-legend-name', text: c.name || '—' }),
                    el('span', { className: 'pie-legend-pct', text: `${pct}%` }),
                    el('span', { className: 'pie-legend-val', text: c.formatted || '—' }),
                ]));
            }
            if (categories.length > maxItems) {
                legend.appendChild(el('li', { className: 'pie-legend-item muted', text: `+${categories.length - maxItems} outras` }));
            }
            pieBody.appendChild(legend);
        } else if (pieBody) {
            pieBody.appendChild(el('div', { className: 'empty empty-sm', text: 'Sem despesas neste mês.' }));
        }

        requestAnimationFrame(() => {
            try { drawBarChart('bar-canvas', trend); } catch (e) { console.error('[Dashboard] barChart:', e); }
            try { drawPieChart('pie-canvas', categories); } catch (e) { console.error('[Dashboard] pieChart:', e); }
        });
    } catch (e) {
        console.error('[Dashboard] charts falhou:', e);
        root.appendChild(el('div', { className: 'empty', text: `Gráficos: ${e.message}` }));
    }

    // --- Bottom: matriz de categorias + contas ---
    const bottom = el('div', { className: 'dashboard-bottom' });

    // Categorias × últimos 6 meses
    try {
        const matrix = categoryMatrix && typeof categoryMatrix === 'object' ? categoryMatrix : { months: [], rows: [] };
        const months = Array.isArray(matrix.months) ? matrix.months : [];
        const rows = Array.isArray(matrix.rows) ? matrix.rows : [];

        const catCard = el('div', { className: 'card' });
        catCard.appendChild(el('div', { className: 'card-header' }, [
            el('div', {}, [
                el('h3', { className: 'card-title', text: 'Despesas por categoria' }),
                el('p', { className: 'card-subtitle', text: 'Últimos 6 meses · pais totalizam subcategorias' }),
            ]),
            el('a', { className: 'btn btn-sm', attrs: { href: '/categories.php' }, text: 'Categorias →' }),
        ]));

        if (rows.length === 0 || months.length === 0) {
            catCard.appendChild(el('div', {
                className: 'empty empty-sm',
                text: 'Sem despesas no período.',
            }));
        } else {
            const wrap = el('div', { className: 'table-wrap cat-matrix-wrap' });
            const table = el('table', { className: 'cat-matrix' });
            const headCells = [
                el('th', { text: 'Categoria' }),
                ...months.map(m => el('th', {
                    text: m.label || m.key,
                    attrs: { style: 'text-align:right' },
                })),
                el('th', { text: 'Total', attrs: { style: 'text-align:right' } }),
            ];
            table.appendChild(el('thead', {}, [el('tr', {}, headCells)]));
            const tbody = el('tbody');

            const cellVal = (bucket) => {
                if (!bucket || !bucket.cents) return el('td', {
                    className: 'amount muted',
                    attrs: { style: 'text-align:right' },
                    text: '—',
                });
                return el('td', {
                    className: 'amount expense',
                    attrs: { style: 'text-align:right;font-variant-numeric:tabular-nums' },
                    text: bucket.formatted || '—',
                });
            };

            for (const row of rows) {
                const parentCells = [
                    el('td', {}, [
                        el('span', { className: 'cat-matrix-parent', text: row.name }),
                    ]),
                    ...months.map(m => cellVal(row.months && row.months[m.key])),
                    cellVal(row.total),
                ];
                tbody.appendChild(el('tr', { className: 'cat-matrix-parent-row' }, parentCells));

                const children = Array.isArray(row.children) ? row.children : [];
                for (const ch of children) {
                    const childCells = [
                        el('td', {}, [
                            el('span', { className: 'cat-matrix-child', text: ch.name }),
                        ]),
                        ...months.map(m => cellVal(ch.months && ch.months[m.key])),
                        cellVal(ch.total),
                    ];
                    tbody.appendChild(el('tr', { className: 'cat-matrix-child-row' }, childCells));
                }
            }
            table.appendChild(tbody);
            wrap.appendChild(table);
            catCard.appendChild(wrap);
        }
        bottom.appendChild(catCard);
    } catch (e) {
        console.error('[Dashboard] category matrix falhou:', e);
        bottom.appendChild(el('div', { className: 'empty', text: `Categorias: ${e.message}` }));
    }

    // Contas (snapshot)
    try {
        const accList = Array.isArray(accounts) ? accounts.filter(a => !a.is_closed) : [];
        accList.sort((a, b) => (b.balance_today_cents ?? b.balance_cents ?? 0) - (a.balance_today_cents ?? a.balance_cents ?? 0));
        const top = accList.slice(0, 6);

        const accCard = el('div', { className: 'card' });
        accCard.appendChild(el('div', { className: 'card-header' }, [
            el('div', {}, [
                el('h3', { className: 'card-title', text: 'Contas' }),
                el('p', { className: 'card-subtitle', text: `${accList.length} aberta${accList.length === 1 ? '' : 's'} · até hoje / final` }),
            ]),
            el('a', { className: 'btn btn-sm', attrs: { href: '/accounts.php' }, text: 'Gerenciar →' }),
        ]));

        if (top.length === 0) {
            accCard.appendChild(el('div', { className: 'empty empty-sm', text: 'Nenhuma conta cadastrada.' }));
        } else {
            const list = el('div', { className: 'dash-accounts' });
            for (const a of top) {
                const todayCents = a.balance_today_cents ?? a.balance_cents ?? 0;
                const finalCents = a.balance_final_cents ?? a.balance_cents ?? 0;
                const todayFmt = a.balance_today_formatted ?? a.balance_formatted ?? '—';
                const finalFmt = a.balance_final_formatted ?? a.balance_formatted ?? '—';
                const negToday = todayCents < 0;
                const negFinal = finalCents < 0;
                list.appendChild(el('div', { className: 'dash-account-row' }, [
                    el('div', { className: 'dash-account-main' }, [
                        el('span', { className: `account-icon ${a.type || 'bank'}`, text: typeEmoji(a.type) }),
                        el('div', {}, [
                            el('div', { className: 'dash-account-name', text: a.name || '—' }),
                            el('div', { className: 'dash-account-meta', text: typeLabel(a.type) + (a.institution_name ? ` · ${a.institution_name}` : '') }),
                        ]),
                    ]),
                    el('div', { className: 'dash-account-balances' }, [
                        el('div', {
                            className: `amount ${negToday ? 'expense' : 'income'}`,
                            attrs: { title: 'Até hoje' },
                            text: todayFmt,
                        }),
                        el('div', {
                            className: `amount amount-final ${negFinal ? 'expense' : 'income'}`,
                            attrs: { title: 'Saldo final (com futuros)' },
                            text: finalFmt,
                        }),
                    ]),
                ]));
            }
            if (accList.length > top.length) {
                list.appendChild(el('div', {
                    className: 'dash-account-more',
                    text: `+${accList.length - top.length} outras contas`,
                }));
            }
            accCard.appendChild(list);
        }
        bottom.appendChild(accCard);
    } catch (e) {
        console.error('[Dashboard] accounts falhou:', e);
    }

    root.appendChild(bottom);
}

function statCard(label, value, accent, sub) {
    return el('div', { className: `stat-card ${accent ? 'accent-' + accent : ''}` }, [
        el('div', { className: 'stat-label', text: label }),
        el('div', { className: `stat-value ${accent || ''}`, text: value }),
        sub ? el('div', { className: 'stat-sub', text: sub }) : null,
    ]);
}

function txRow(t) {
    const isCredit = t && t.is_credit;
    const amountStr = (t && t.amount_formatted) ? t.amount_formatted.replace('R$ ', '') : '—';
    const status = (t && t.status) || 'none';
    return el('div', { className: 'tx-row' }, [
        el('div', { className: `tx-icon ${isCredit ? 'income' : 'expense'}` }, [
            el('span', { className: `icon icon-arrow-${isCredit ? 'up' : 'down'}` }),
        ]),
        el('div', { className: 'tx-main' }, [
            el('div', { className: 'tx-payee', text: (t && (t.payee_name || t.memo || t.category_full)) || '—' }),
            el('div', { className: 'tx-meta', text: `${(t && t.date) || '—'} · ${(t && t.account_name) || '—'} · ${(t && t.category_full) || '—'}` }),
        ]),
        el('span', { className: `tx-status ${status}`, text: statusLabel(status) }),
        el('div', { className: `tx-amount ${isCredit ? 'income' : 'expense'}`, text: (isCredit ? '+' : '') + amountStr }),
    ]);
}

// ===== Canvas charts (vanilla, sem libs) =====
function drawBarChart(canvasId, data) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, rect.width, rect.height);

    if (!data || data.length === 0) {
        ctx.fillStyle = '#8b9a94';
        ctx.font = '13px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Sem dados.', rect.width / 2, rect.height / 2);
        return;
    }

    const max = Math.max(...data.flatMap(d => [d.income_cents, Math.abs(d.expense_cents)]), 100) / 100;
    const W = rect.width;
    const H = rect.height;
    const padding = { top: 12, right: 12, bottom: 28, left: 52 };
    const chartW = W - padding.left - padding.right;
    const chartH = H - padding.top - padding.bottom;
    const barGroupW = chartW / data.length;
    const barW = Math.max(6, barGroupW * 0.28);

    // Grid + Y axis (dark theme)
    const ticks = 4;
    for (let i = 0; i <= ticks; i++) {
        const v = (max * i / ticks);
        const y = padding.top + chartH - (chartH * i / ticks);
        ctx.strokeStyle = i === 0 ? '#2f3d38' : '#1e2925';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(padding.left, y);
        ctx.lineTo(padding.left + chartW, y);
        ctx.stroke();
        ctx.fillStyle = '#8b9a94';
        ctx.font = '11px system-ui, sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText(`R$ ${v >= 1000 ? (v / 1000).toFixed(1) + 'k' : Math.round(v)}`, padding.left - 6, y + 4);
    }

    // Bars with rounded top
    data.forEach((d, i) => {
        const xBase = padding.left + barGroupW * i + barGroupW / 2;
        const drawBar = (x, h, color) => {
            if (h <= 0) return;
            const y = padding.top + chartH - h;
            const r = Math.min(3, barW / 2, h);
            ctx.fillStyle = color;
            ctx.beginPath();
            ctx.moveTo(x, y + h);
            ctx.lineTo(x, y + r);
            ctx.quadraticCurveTo(x, y, x + r, y);
            ctx.lineTo(x + barW - r, y);
            ctx.quadraticCurveTo(x + barW, y, x + barW, y + r);
            ctx.lineTo(x + barW, y + h);
            ctx.closePath();
            ctx.fill();
        };
        const incH = (d.income_cents / 100) / max * chartH;
        const expH = (Math.abs(d.expense_cents) / 100) / max * chartH;
        drawBar(xBase - barW - 2, incH, '#7dcea0');
        drawBar(xBase + 2, expH, '#f08080');
        ctx.fillStyle = '#8b9a94';
        ctx.textAlign = 'center';
        ctx.font = '10px system-ui, sans-serif';
        ctx.fillText(d.label, xBase, padding.top + chartH + 16);
    });
}

function drawPieChart(canvasId, data) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, rect.width, rect.height);

    if (!data || data.length === 0) {
        ctx.fillStyle = '#8b9a94';
        ctx.font = '13px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Sem despesas neste mês.', rect.width / 2, rect.height / 2);
        return;
    }

    const colors = ['#7dcea0', '#f08080', '#e6b84d', '#8a9bb0', '#c49a6c', '#a0a8b4'];
    const total = data.reduce((acc, d) => acc + d.cents, 0) || 1;
    const cx = rect.width / 2;
    const cy = rect.height / 2;
    const radius = Math.min(rect.width, rect.height) / 2 - 10;
    const inner = radius * 0.55;
    let angle = -Math.PI / 2;

    data.slice(0, 6).forEach((d, i) => {
        const slice = (d.cents / total) * Math.PI * 2;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, radius, angle, angle + slice);
        ctx.closePath();
        ctx.fillStyle = colors[i % colors.length];
        ctx.fill();
        angle += slice;
    });

    // Inner circle (donut) — surface dark
    ctx.beginPath();
    ctx.arc(cx, cy, inner, 0, Math.PI * 2);
    ctx.fillStyle = '#141c19';
    ctx.fill();

    // Total in the middle
    ctx.fillStyle = '#e8efec';
    ctx.font = 'bold 14px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('Total', cx, cy - 6);
    ctx.font = '11px sans-serif';
    ctx.fillStyle = '#8b9a94';
    ctx.fillText(formatCents(total), cx, cy + 12);
}

// ============================================================
// TRANSACTIONS
// ============================================================
async function TransactionsView(root, actions) {
    if (!root) { console.error('[Transactions] root é null'); return; }

    const TX_FILTERS_KEY = 'moneyapp.tx.filters';

    function loadTxFilters() {
        try {
            const raw = localStorage.getItem(TX_FILTERS_KEY);
            if (!raw) return null;
            const data = JSON.parse(raw);
            return data && typeof data === 'object' ? data : null;
        } catch (_) {
            return null;
        }
    }

    function saveTxFilters() {
        try {
            localStorage.setItem(TX_FILTERS_KEY, JSON.stringify({
                accountId: state.accountId,
                month: state.month,
                showDeleted: !!state.showDeleted,
                status: {
                    none: !!state.status.none,
                    cleared: !!state.status.cleared,
                    reconciled: !!state.status.reconciled,
                },
                search: state.search || '',
            }));
        } catch (_) { /* private mode / quota */ }
    }

    const saved = loadTxFilters();
    // Migração: pendingOnly antigo → só pendentes
    let initialStatus = { none: true, cleared: true, reconciled: true };
    if (saved && saved.status && typeof saved.status === 'object') {
        initialStatus = {
            none: saved.status.none !== false,
            cleared: saved.status.cleared !== false,
            reconciled: saved.status.reconciled !== false,
        };
    } else if (saved && saved.pendingOnly) {
        initialStatus = { none: true, cleared: false, reconciled: false };
    }
    let state = {
        accountId: saved && saved.accountId != null ? Number(saved.accountId) || null : null,
        month: saved && Object.prototype.hasOwnProperty.call(saved, 'month')
            ? (saved.month || null)
            : currentMonthIso(),
        showDeleted: !!(saved && saved.showDeleted),
        status: initialStatus,
        search: (saved && typeof saved.search === 'string') ? saved.search : '',
    };
    let accounts = [];
    let categories = [];
    let payees = [];
    let rows = [];

    if (actions) actions.innerHTML = '';
    const newBtn = el('button', {
        className: 'btn btn-primary',
        style: 'padding: 10px 18px; font-size: 14px; font-weight: 600;',
        on: { click: () => openTxForm() },
    }, [
        el('span', { text: '+ Nova transação' }),
    ]);
    actions.appendChild(newBtn);

    root.innerHTML = '<div class="loading">Carregando transações...</div>';

    try {
        const [accs, cats, pays] = await Promise.all([
            api('/api/accounts.php'),
            api('/api/categories.php'),
            api('/api/payees.php'),
        ]);
        accounts = Array.isArray(accs) ? accs : [];
        categories = Array.isArray(cats) ? cats : [];
        payees = Array.isArray(pays) ? pays : [];
        // Conta salva que não existe mais → limpa
        if (state.accountId && !accounts.some(a => Number(a.id) === Number(state.accountId))) {
            state.accountId = null;
            saveTxFilters();
        }
        console.log('[Transactions] dados carregados:', { accounts: accounts.length, categories: categories.length, payees: payees.length });
    } catch (e) {
        console.error('[Transactions] API falhou:', e);
        root.innerHTML = `<div class="empty"><p>Erro ao carregar dados: ${escapeHtml(e.message)}</p><p style="font-size:12px;margin-top:8px">Verifique o log do container: <code>docker compose logs moneyapp</code></p></div>`;
        return;
    }

    try {
        render();
    } catch (e) {
        console.error('[Transactions] render() falhou:', e);
        root.innerHTML = `<div class="empty"><p>Erro ao renderizar página: ${escapeHtml(e.message)}</p><pre style="font-size:11px;margin-top:8px;white-space:pre-wrap">${escapeHtml(e.stack || '')}</pre></div>`;
    }

    async function reload() {
        saveTxFilters();
        const params = new URLSearchParams();
        if (state.accountId) params.set('account_id', state.accountId);
        if (state.month) params.set('month', state.month);
        if (state.showDeleted) params.set('show_deleted', '1');
        // Status filtrado no cliente (permite múltiplos sem distorcer saldo).
        params.set('limit', '200');
        try {
            rows = await api('/api/transactions.php?' + params.toString());
        } catch (e) {
            toast(e.message, 'error');
            rows = [];
        }
        renderList();
    }

    function render() {
        root.innerHTML = '';
        // Filters
        const filters = el('div', { className: 'filters' });

        const accountSel = buildSelect(
            [{ value: '', label: 'Todas as contas' }, ...accounts.map(a => ({ value: a.id, label: a.name }))],
            state.accountId ?? '',
        );
        accountSel.addEventListener('change', () => {
            state.accountId = accountSel.value ? Number(accountSel.value) : null;
            reload();
        });

        const monthSel = buildSelect(
            [{ value: '', label: 'Todos os meses' }, ...monthOptions(12)],
            state.month ?? '',
        );
        monthSel.addEventListener('change', () => {
            state.month = monthSel.value || null;
            reload();
        });

        const searchInput = buildInput('search', state.search, 'Beneficiário, memo...');
        searchInput.addEventListener('input', () => {
            state.search = searchInput.value.toLowerCase();
            saveTxFilters();
            renderList();
        });

        const makeFlag = (checked, label, onChange) => {
            const cb = el('input', { attrs: { type: 'checkbox' } });
            cb.checked = checked;
            cb.addEventListener('change', () => onChange(cb.checked));
            return el('label', { className: 'filter-flag' }, [
                cb,
                el('span', { text: label }),
            ]);
        };

        const statusWrap = el('div', { className: 'field' }, [
            el('label', { className: 'label', text: 'Status' }),
            el('div', { className: 'filter-flags' }, [
                makeFlag(state.status.none, 'Previsão', (v) => {
                    state.status.none = v;
                    saveTxFilters();
                    renderList();
                }),
                makeFlag(state.status.cleared, 'No banco', (v) => {
                    state.status.cleared = v;
                    saveTxFilters();
                    renderList();
                }),
                makeFlag(state.status.reconciled, 'Conciliadas', (v) => {
                    state.status.reconciled = v;
                    saveTxFilters();
                    renderList();
                }),
            ]),
        ]);

        const flagsWrap = el('div', { className: 'field' }, [
            el('label', { className: 'label', text: 'Opções' }),
            el('div', { className: 'filter-flags' }, [
                makeFlag(state.showDeleted, 'Mostrar excluídas', (v) => { state.showDeleted = v; reload(); }),
            ]),
        ]);

        filters.appendChild(field('Conta', accountSel));
        filters.appendChild(field('Mês', monthSel));
        filters.appendChild(field('Busca', searchInput));
        filters.appendChild(statusWrap);
        filters.appendChild(flagsWrap);

        root.appendChild(filters);

        // Table card
        const card = el('div', { className: 'card' });
        const cardHead = el('div', { className: 'card-header' }, [
            el('h3', { className: 'card-title', text: 'Transações' }),
        ]);
        card.appendChild(cardHead);
        const listWrap = el('div', { className: 'table-wrap', attrs: { id: 'tx-list' } });
        card.appendChild(listWrap);
        root.appendChild(card);

        reload();
    }

    function renderList() {
        const wrap = document.getElementById('tx-list');
        if (!wrap) return;
        const statusAllowed = [];
        if (state.status.none) statusAllowed.push('none');
        if (state.status.cleared) statusAllowed.push('cleared');
        if (state.status.reconciled) statusAllowed.push('reconciled');
        // Nenhum status marcado → lista vazia (escolha explícita)
        const filtered = rows.filter(t => {
            if (statusAllowed.length === 0) return false;
            if (!statusAllowed.includes(t.status || 'none')) return false;
            if (!state.search) return true;
            const s = state.search;
            return (t.payee_name?.toLowerCase().includes(s) ||
                    t.memo?.toLowerCase().includes(s) ||
                    t.category_full?.toLowerCase().includes(s) ||
                    t.account_name?.toLowerCase().includes(s));
        });

        if (filtered.length === 0) {
            wrap.innerHTML = '<div class="empty"><p>Nenhuma transação encontrada.</p></div>';
            return;
        }

        // Lista vem em ordem cronológica ASC: último lançamento ≤ hoje fica
        // logo antes dos futuros (amanhã em diante).
        const today = todayIso();
        let lastCurrentIdx = -1;
        for (let i = 0; i < filtered.length; i++) {
            const iso = toIsoDate(filtered[i].date);
            if (iso && iso <= today) lastCurrentIdx = i;
        }

        const table = el('table');
        const thead = el('thead', {}, [
            el('tr', {}, [
                el('th', { text: 'Data' }),
                el('th', { text: 'Beneficiário / Memo' }),
                el('th', { text: 'Conta' }),
                el('th', { text: 'Categoria' }),
                el('th', { text: 'Valor', attrs: { style: 'text-align:right' } }),
                el('th', { text: 'Saldo', attrs: { style: 'text-align:right' } }),
                el('th', { text: 'Status', attrs: { style: 'text-align:center' } }),
                el('th', { text: 'Ações', attrs: { style: 'text-align:right' } }),
            ]),
        ]);
        const tbody = el('tbody');
        filtered.forEach((t, idx) => {
            const balText = t.balance_formatted ?? '—';
            const balNeg = (t.balance_cents != null && t.balance_cents < 0);
            const iso = toIsoDate(t.date);
            const isFuture = iso && iso > today;
            const isLastCurrent = idx === lastCurrentIdx && lastCurrentIdx >= 0;
            const cells = [
                el('td', {}, [
                    el('span', { text: t.date }),
                    isFuture
                        ? el('span', { className: 'tx-future-tag', text: ' futuro' })
                        : null,
                ]),
                el('td', {}, [
                    el('div', { text: t.payee_name || t.memo || '—', style: 'font-weight:500' }),
                    el('div', { text: t.memo || '—', style: 'font-size:11px;color:var(--text-muted)' }),
                ]),
                el('td', { text: t.account_name, attrs: { style: 'font-size:11px' } }),
                el('td', {}, t.category_full ? [el('span', { className: 'badge', text: t.category_full })] : ['—']),
                el('td', { className: `amount ${t.is_credit ? 'income' : 'expense'}`, text: (t.is_credit ? '+' : '') + t.amount_formatted }),
                el('td', {
                    className: `amount ${balNeg ? 'expense' : ''}`,
                    attrs: { style: 'text-align:right;font-variant-numeric:tabular-nums' },
                    text: balText,
                }),
            ];
            const statusBtn = t.deleted_at
                ? el('span', { className: `tx-status ${t.status}`, text: statusLabel(t.status) })
                : el('button', {
                    className: `tx-status tx-status-btn ${t.status}`,
                    attrs: { type: 'button', title: statusTitle(t.status) },
                    text: statusLabel(t.status),
                    on: { click: (ev) => { ev.stopPropagation(); cycleStatus(t); } },
                });
            cells.push(el('td', { attrs: { style: 'text-align:center' } }, [statusBtn]));
            cells.push(el('td', { attrs: { style: 'text-align:right;white-space:nowrap' } }, [
                t.deleted_at
                    ? el('button', { className: 'btn btn-sm', text: 'Restaurar', on: { click: () => restoreTx(t.id) } })
                    : el('span', {}, [
                        el('button', {
                            className: 'btn-icon',
                            attrs: { type: 'button', title: 'Editar' },
                            on: { click: () => openTxForm(t) },
                        }, [el('span', { className: 'icon icon-edit' })]),
                        el('button', {
                            className: 'btn-icon',
                            attrs: { type: 'button', title: 'Duplicar' },
                            on: { click: () => openTxForm(t, { asDuplicate: true }) },
                        }, [el('span', { className: 'icon icon-copy' })]),
                        el('button', {
                            className: 'btn-icon',
                            attrs: { type: 'button', title: 'Gerar previsão no mês seguinte' },
                            on: { click: () => generateNextMonthForecast(t) },
                        }, [el('span', { className: 'icon icon-next-month' })]),
                        el('button', {
                            className: 'btn-icon danger',
                            attrs: { type: 'button', title: 'Excluir' },
                            on: { click: () => confirmDelete(t) },
                        }, [el('span', { className: 'icon icon-trash' })]),
                    ]),
            ]));
            const rowClasses = [];
            if (t.deleted_at) rowClasses.push('deleted');
            if (isFuture) rowClasses.push('tx-row-future');
            if (isLastCurrent) rowClasses.push('tx-row-last-current');
            const row = el('tr', { className: rowClasses.join(' ') }, cells);
            if (t.deleted_at) row.style.opacity = '0.5';
            tbody.appendChild(row);
        });
        table.appendChild(thead);
        table.appendChild(tbody);
        wrap.innerHTML = '';
        wrap.appendChild(table);
    }

    async function cycleStatus(t) {
        const order = ['none', 'cleared', 'reconciled'];
        const idx = order.indexOf(t.status);
        const next = order[(idx + 1) % order.length];
        try {
            await api('/api/transactions.php?id=' + t.id, {
                method: 'PATCH',
                body: { status: next },
            });
            t.status = next;
            toast(`Status: ${statusLabel(next)}`, 'success');
            renderList();
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    /**
     * Cria no mês seguinte uma cópia do lançamento como Previsão (status none).
     * Mantém conta, valor, categoria, beneficiário, memo e número; só avança a data.
     */
    async function generateNextMonthForecast(t) {
        if (!t || t.deleted_at) return;
        const nextDate = addMonthsToDate(t.date, 1);
        if (!nextDate) {
            toast('Data do lançamento inválida.', 'error');
            return;
        }
        const cents = Math.abs(Number(t.amount_cents) || 0);
        const intPart = Math.floor(cents / 100);
        const frac = String(cents % 100).padStart(2, '0');
        const amount = (t.is_credit ? '+' : '-') + `${intPart}.${frac}`;
        const payload = {
            account_id: t.account_id,
            date: nextDate,
            amount,
            payee_id: t.payee_id || null,
            category_id: t.category_id || null,
            memo: t.memo || null,
            number: t.number || null,
            status: 'none',
        };
        if (!payload.account_id || !payload.category_id) {
            toast('Conta e categoria são obrigatórias para gerar a previsão.', 'error');
            return;
        }
        try {
            await api('/api/transactions.php', { method: 'POST', body: payload });
            toast(`Previsão criada em ${nextDate}.`, 'success');
            reload();
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    async function openTxForm(source = null, opts = {}) {
        try {
            const isDuplicate = !!(opts && opts.asDuplicate);
            // Edição real só quando há id e não é duplicata.
            const isEdit = !!(source && source.id && !isDuplicate);
            const template = source; // prefills (editar ou duplicar)
            console.log('[Transactions] openTxForm', isEdit ? 'editando ' + source.id : (isDuplicate ? 'duplicando ' + source.id : 'nova'));

        const isCredit = template ? template.is_credit : false;
        const form = el('div');

        const toggleGroup = el('div', { className: 'toggle-group' });
        const expBtn = el('button', { className: `toggle-btn expense ${!isCredit ? 'active' : ''}`, text: 'Despesa', on: { click: () => { expBtn.classList.add('active'); incBtn.classList.remove('active'); kind = 'expense'; filterCats(); } } });
        const incBtn = el('button', { className: `toggle-btn income ${isCredit ? 'active' : ''}`, text: 'Receita', on: { click: () => { incBtn.classList.add('active'); expBtn.classList.remove('active'); kind = 'income'; filterCats(); } } });
        let kind = isCredit ? 'income' : 'expense';
        toggleGroup.appendChild(expBtn);
        toggleGroup.appendChild(incBtn);
        form.appendChild(toggleGroup);

        const amountInput = buildInput('text', template && template.amount_formatted ? template.amount_formatted.replace('R$ ', '').replace(/^-/, '') : '', '0,00');
        amountInput.inputMode = 'decimal';
        amountInput.setAttribute('inputmode', 'decimal');
        amountInput.autocomplete = 'off';
        // Data nativa (YYYY-MM-DD). API aceita ISO e pt-BR.
        // Duplicar → hoje; editar → data da tx; nova → hoje.
        const dateInput = buildInput(
            'date',
            isDuplicate
                ? todayIso()
                : (template && template.date ? toIsoDate(template.date) : todayIso()),
            '',
        );

        const accountSel = buildSelect(
            accounts.map(a => ({ value: a.id, label: `${a.name} (${a.balance_formatted})` })),
            template ? template.account_id : (state.accountId ?? (accounts[0]?.id ?? '')),
            'Selecione a conta',
        );

        const catSel = buildSelect([], template?.category_id ?? '', 'Selecione a categoria');
        const payeeSel = buildSelect(
            [{ value: '', label: '— Sem beneficiário —' }, ...payees.map(p => ({ value: p.id, label: p.name }))],
            template?.payee_id ?? '',
        );

        const memoInput = buildInput('text', template?.memo ?? '');
        const numberInput = buildInput('text', isDuplicate ? '' : (template?.number ?? ''));
        const statusSel = buildSelect(
            [
                { value: 'none', label: 'Previsão (não lançado no banco)' },
                { value: 'cleared', label: 'No banco (já lançado)' },
                { value: 'reconciled', label: 'Conciliada (conferida no extrato)' },
            ],
            isDuplicate ? 'none' : (template?.status ?? 'none'),
        );

        function filterCats() {
            const filtered = categories
                .filter(c => c.kind === kind)
                .map(c => ({
                    ...c,
                    label: c.parent_name ? `${c.parent_name} › ${c.name}` : c.name,
                }))
                .sort((a, b) => a.label.localeCompare(b.label, 'pt-BR', { sensitivity: 'base' }));
            catSel.innerHTML = '';
            catSel.appendChild(el('option', { attrs: { value: '' }, text: 'Selecione a categoria' }));
            for (const c of filtered) {
                const opt = el('option', { attrs: { value: c.id }, text: c.label });
                if (String(c.id) === String(template?.category_id)) opt.selected = true;
                catSel.appendChild(opt);
            }
        }
        filterCats();

        // Atalhos de data: Hoje / Ontem / Amanhã
        const dateQuick = el('div', { className: 'date-quick' }, [
            el('button', {
                className: 'btn btn-sm',
                attrs: { type: 'button' },
                text: 'Hoje',
                on: { click: () => { dateInput.value = todayIso(); } },
            }),
            el('button', {
                className: 'btn btn-sm',
                attrs: { type: 'button' },
                text: 'Ontem',
                on: { click: () => { dateInput.value = shiftIsoDays(-1); } },
            }),
            el('button', {
                className: 'btn btn-sm',
                attrs: { type: 'button' },
                text: 'Amanhã',
                on: { click: () => { dateInput.value = shiftIsoDays(1); } },
            }),
        ]);
        const dateField = el('div', { className: 'field' }, [
            el('label', { className: 'label', text: 'Data' }),
            dateInput,
            dateQuick,
        ]);

        form.appendChild(el('div', { className: 'field-row' }, [
            field('Valor (R$)', amountInput, 'Ex.: 0,02 ou 1.181,47'),
            dateField,
        ]));
        form.appendChild(field('Conta', accountSel));
        form.appendChild(field('Categoria', catSel));
        form.appendChild(field('Beneficiário', payeeSel));
        form.appendChild(el('div', { className: 'field-row' }, [
            field('Memo', memoInput),
            field('Nº', numberInput),
        ]));
        form.appendChild(field('Status', statusSel));

        const saveLabel = isEdit ? 'Salvar alterações' : (isDuplicate ? 'Criar cópia' : 'Criar transação');
        const modalTitle = isEdit ? 'Editar transação' : (isDuplicate ? 'Duplicar transação' : 'Nova transação');
        const saveBtn = el('button', { className: 'btn btn-primary', text: saveLabel });
        const cancelBtn = el('button', { className: 'btn', text: 'Cancelar' });
        const { close } = openModal(modalTitle, form, [cancelBtn, saveBtn]);

        cancelBtn.addEventListener('click', close);
        saveBtn.addEventListener('click', async () => {
            let amountCanon;
            try {
                amountCanon = normalizeMoneyInput(amountInput.value || '0');
            } catch (normErr) {
                toast(normErr.message || 'Valor inválido.', 'error');
                return;
            }
            const amount = (kind === 'expense' ? '-' : '+') + amountCanon;
            const payload = {
                account_id: Number(accountSel.value),
                date: dateInput.value,
                amount,
                payee_id: payeeSel.value ? Number(payeeSel.value) : null,
                category_id: catSel.value ? Number(catSel.value) : null,
                memo: memoInput.value || null,
                number: numberInput.value || null,
                status: statusSel.value,
            };
            if (!payload.account_id) { toast('Selecione a conta.', 'error'); return; }
            if (!payload.category_id) { toast('Selecione a categoria.', 'error'); return; }
            if (!payload.date) { toast('Informe a data.', 'error'); return; }
            saveBtn.disabled = true;
            saveBtn.textContent = 'Salvando...';
            try {
                if (isEdit) {
                    await api('/api/transactions.php?id=' + source.id, { method: 'PATCH', body: payload });
                    toast('Transação atualizada.', 'success');
                } else {
                    await api('/api/transactions.php', { method: 'POST', body: payload });
                    toast(isDuplicate ? 'Cópia criada.' : 'Transação criada.', 'success');
                }
                close();
                reload();
            } catch (e) {
                toast(e.message, 'error');
                saveBtn.disabled = false;
                saveBtn.textContent = saveLabel;
            }
        });

        amountInput.focus();
        } catch (formErr) {
            console.error('[Transactions] openTxForm falhou:', formErr);
            const errBody = el('div', {}, [
                el('p', { text: 'Erro ao abrir formulário: ' + formErr.message, style: 'color:#d24656;font-weight:600' }),
                el('pre', { text: formErr.stack || '', style: 'font-size:11px;white-space:pre-wrap;margin-top:8px;background:#1a2421;padding:8px;border-radius:4px;color:#8b9a94' }),
            ]);
            openModal('Erro', errBody, [
                el('button', { className: 'btn', text: 'Fechar', on: { click: () => document.getElementById('modal-root').innerHTML = '' } }),
            ]);
        }
    }

    function confirmDelete(t) {
        const body = el('div', {}, [
            el('p', { text: `Excluir a transação de ${t.amount_formatted} (${t.payee_name || t.memo || '—'})?` }),
            el('p', { text: 'Será marcada como excluída (soft delete). Você pode restaurá-la depois.', attrs: { style: 'font-size:12px;color:#5c6b65;margin-top:8px' } }),
        ]);
        const confirmBtn = el('button', { className: 'btn btn-danger', text: 'Excluir' });
        const cancelBtn = el('button', { className: 'btn', text: 'Cancelar' });
        const { close } = openModal('Excluir transação?', body, [cancelBtn, confirmBtn]);
        cancelBtn.addEventListener('click', close);
        confirmBtn.addEventListener('click', async () => {
            confirmBtn.disabled = true;
            try {
                await api('/api/transactions.php?id=' + t.id, { method: 'DELETE' });
                toast('Transação excluída.', 'success');
                close();
                reload();
            } catch (e) {
                toast(e.message, 'error');
                confirmBtn.disabled = false;
            }
        });
    }

    async function restoreTx(id) {
        try {
            await api('/api/transactions.php?id=' + id + '&action=restore', { method: 'POST' });
            toast('Transação restaurada.', 'success');
            reload();
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    reload();
}

// ============================================================
// ACCOUNTS
// ============================================================
async function AccountsView(root, actions) {
    let accounts = [];
    let institutions = [];

    actions.innerHTML = '';
    const newBtn = el('button', { className: 'btn btn-primary', on: { click: () => openAccountForm() } }, [
        el('span', { className: 'icon icon-plus' }),
        el('span', { text: 'Nova conta' }),
    ]);
    actions.appendChild(newBtn);

    root.innerHTML = '<div class="loading">Carregando</div>';

    try {
        const [accs, insts] = await Promise.all([
            api('/api/accounts.php'),
            api('/api/institutions.php'),
        ]);
        accounts = accs;
        institutions = insts;
    } catch (e) {
        root.innerHTML = `<div class="empty"><p>Erro ao carregar: ${escapeHtml(e.message)}</p></div>`;
        return;
    }

    render();

    function render() {
        root.innerHTML = '';
        const openAccounts = accounts.filter(a => !a.is_closed);
        const sumBy = (types, field) => openAccounts
            .filter(a => types.includes(a.type))
            .reduce((s, a) => s + (a[field] ?? a.balance_cents ?? 0), 0);
        const assetTypes = ['bank', 'cash', 'investment', 'asset'];
        const liabTypes = ['credit_card', 'loan', 'liability'];
        const netToday = sumBy(assetTypes, 'balance_today_cents') + sumBy(liabTypes, 'balance_today_cents');
        const netFinal = sumBy(assetTypes, 'balance_final_cents') + sumBy(liabTypes, 'balance_final_cents');
        root.appendChild(el('p', {
            className: 'page-subtitle',
            text: `Até hoje: ${formatCents(netToday)} · Final (c/ futuros): ${formatCents(netFinal)} · ${accounts.length} conta(s)`,
        }));

        if (accounts.length === 0) {
            root.appendChild(el('div', { className: 'card' }, [
                el('div', { className: 'empty', text: 'Nenhuma conta cadastrada.' }),
            ]));
            return;
        }

        const grid = el('div', { className: 'accounts-grid' });
        for (const a of accounts) {
            const card = el('div', { className: 'account-card' });
            const icon = el('div', { className: `account-icon ${a.type}` }, [el('span', { text: typeEmoji(a.type) })]);
            const head = el('div', { className: 'head' }, [
                icon,
                el('div', {}, [
                    el('div', { className: 'account-name', text: a.name }),
                    el('div', { className: 'account-type', text: `${typeLabel(a.type)}${a.institution_name ? ' · ' + a.institution_name : ''}` }),
                ]),
            ]);
            const todayCents = a.balance_today_cents ?? a.balance_cents;
            const finalCents = a.balance_final_cents ?? a.balance_cents;
            const todayFmt = a.balance_today_formatted ?? a.balance_formatted;
            const finalFmt = a.balance_final_formatted ?? a.balance_formatted;
            const balanceBlock = el('div', { className: 'account-balances' }, [
                el('div', { className: 'account-balance-row' }, [
                    el('div', { className: 'balance-label', text: 'Até hoje' }),
                    el('div', {
                        className: `balance-value ${todayCents < 0 ? 'negative' : ''}`,
                        text: todayFmt,
                    }),
                ]),
                el('div', { className: 'account-balance-row' }, [
                    el('div', { className: 'balance-label', text: 'Saldo final' }),
                    el('div', {
                        className: `balance-value balance-value-secondary ${finalCents < 0 ? 'negative' : ''}`,
                        text: finalFmt,
                    }),
                ]),
                el('div', {
                    className: 'account-type',
                    text: `Abertura: ${a.opening_date} · ${a.opening_balance_formatted || '—'}`,
                    attrs: { style: 'margin-top:6px' },
                }),
                a.comment
                    ? el('div', {
                        className: 'account-type',
                        text: a.comment,
                        attrs: { style: 'margin-top:6px;font-style:italic;opacity:0.85;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap' },
                    })
                    : null,
            ]);
            const foot = el('div', { className: 'account-foot' }, [
                el('span', { text: a.is_favorite ? '★ Favorita' : typeLabel(a.type) }),
                el('span', {}, [
                    el('button', { className: 'btn-icon', on: { click: () => openAccountForm(a) } }, [el('span', { className: 'icon icon-edit' })]),
                    el('button', { className: 'btn-icon danger', on: { click: () => confirmDeleteAccount(a) } }, [el('span', { className: 'icon icon-trash' })]),
                ]),
            ]);
            card.appendChild(head);
            card.appendChild(balanceBlock);
            card.appendChild(foot);
            grid.appendChild(card);
        }
        root.appendChild(grid);
    }

    function openAccountForm(editing = null) {
        const form = el('div');
        const nameInput = buildInput('text', editing?.name ?? '', 'Ex.: Conta Corrente Itaú');
        const typeSel = buildSelect(
            [
                { value: 'bank', label: 'Conta bancária' },
                { value: 'credit_card', label: 'Cartão de crédito' },
                { value: 'cash', label: 'Dinheiro' },
                { value: 'investment', label: 'Investimento' },
                { value: 'loan', label: 'Empréstimo' },
                { value: 'asset', label: 'Ativo' },
                { value: 'liability', label: 'Passivo' },
            ],
            editing?.type ?? 'bank',
        );
        const instSel = buildSelect(
            [{ value: '', label: '— Sem instituição —' }, ...institutions.map(i => ({ value: i.id, label: i.name }))],
            editing?.institution_id ?? '',
        );
        const abbrInput = buildInput('text', editing?.abbreviation ?? '', 'Ex.: CC Itaú');
        const numInput = buildInput('text', editing?.account_number ?? '');
        const openBalInput = buildInput(
            'text',
            editing
                ? formatCents(editing.opening_balance_cents ?? 0).replace('R$ ', '').replace(/^-/, (editing.opening_balance_cents ?? 0) < 0 ? '-' : '')
                : '0,00',
            '0,00',
        );
        openBalInput.inputMode = 'decimal';
        openBalInput.setAttribute('inputmode', 'decimal');
        const openDateInput = buildInput(
            'date',
            editing?.opening_date ? toIsoDate(editing.opening_date) : todayIso(),
            '',
        );
        const commentInput = buildInput('text', editing?.comment ?? '', 'Observações da conta...');
        const favCb = el('input', { attrs: { type: 'checkbox' } });
        favCb.checked = editing?.is_favorite ?? false;

        // Saldo/data de abertura: visíveis sempre; só editáveis na criação.
        if (editing) {
            openBalInput.disabled = true;
            openDateInput.disabled = true;
        }

        form.appendChild(field('Nome *', nameInput));
        form.appendChild(el('div', { className: 'field-row' }, [
            field('Tipo', typeSel),
            field('Instituição', instSel),
        ]));
        form.appendChild(el('div', { className: 'field-row' }, [
            field('Abreviação', abbrInput),
            field('Número', numInput),
        ]));
        form.appendChild(el('div', { className: 'field-row' }, [
            field(
                'Saldo de abertura (R$)',
                openBalInput,
                editing ? 'Definido na criação (não editável)' : 'Saldo no início do dia de abertura',
            ),
            field(
                'Data de abertura',
                openDateInput,
                editing ? 'Definida na criação (não editável)' : null,
            ),
        ]));
        form.appendChild(field('Comentário', commentInput));
        form.appendChild(el('label', { style: 'display:flex;align-items:center;gap:8px;font-size:12px;margin-top:8px' }, [
            favCb,
            el('span', { text: 'Favorita' }),
        ]));

        const saveBtn = el('button', { className: 'btn btn-primary', text: editing ? 'Salvar alterações' : 'Criar conta' });
        const cancelBtn = el('button', { className: 'btn', text: 'Cancelar' });
        const { close } = openModal(editing ? 'Editar conta' : 'Nova conta', form, [cancelBtn, saveBtn]);
        cancelBtn.addEventListener('click', close);
        saveBtn.addEventListener('click', async () => {
            if (!nameInput.value.trim()) { toast('Informe o nome.', 'error'); return; }
            const payload = {
                name: nameInput.value.trim(),
                type: typeSel.value,
                institution_id: instSel.value ? Number(instSel.value) : null,
                abbreviation: abbrInput.value || null,
                account_number: numInput.value || null,
                comment: commentInput.value.trim() || null,
                is_favorite: favCb.checked,
            };
            if (!editing) {
                try {
                    const raw = (openBalInput.value || '0').trim();
                    // Permite saldo negativo (ex.: cartão) no cadastro.
                    const neg = raw.startsWith('-');
                    const canon = normalizeMoneyInput(neg ? raw.slice(1) : raw);
                    payload.opening_balance = (neg ? '-' : '') + canon;
                } catch (normErr) {
                    toast(normErr.message || 'Saldo de abertura inválido.', 'error');
                    return;
                }
                if (!openDateInput.value) { toast('Informe a data de abertura.', 'error'); return; }
                payload.opening_date = openDateInput.value;
            }
            saveBtn.disabled = true;
            try {
                if (editing) {
                    await api('/api/accounts.php?id=' + editing.id, { method: 'PATCH', body: payload });
                    toast('Conta atualizada.', 'success');
                } else {
                    await api('/api/accounts.php', { method: 'POST', body: payload });
                    toast('Conta criada.', 'success');
                }
                close();
                AccountsView(root, actions);
                institutions = await api('/api/institutions.php');
            } catch (e) {
                toast(e.message, 'error');
                saveBtn.disabled = false;
            }
        });
        nameInput.focus();
    }

    function confirmDeleteAccount(a) {
        const body = el('div', {}, [
            el('p', { text: `Excluir a conta "${a.name}"?` }),
            el('p', { text: 'Só é possível excluir contas sem transações ativas. Caso contrário, feche a conta.', attrs: { style: 'font-size:12px;color:#5c6b65;margin-top:8px' } }),
        ]);
        const confirmBtn = el('button', { className: 'btn btn-danger', text: 'Excluir' });
        const cancelBtn = el('button', { className: 'btn', text: 'Cancelar' });
        const { close } = openModal('Excluir conta?', body, [cancelBtn, confirmBtn]);
        cancelBtn.addEventListener('click', close);
        confirmBtn.addEventListener('click', async () => {
            confirmBtn.disabled = true;
            try {
                await api('/api/accounts.php?id=' + a.id, { method: 'DELETE' });
                toast('Conta excluída.', 'success');
                close();
                AccountsView(root, actions);
            } catch (e) {
                toast(e.message, 'error');
                confirmBtn.disabled = false;
            }
        });
    }
}

// ============================================================
// CATEGORIES
// ============================================================
async function CategoriesView(root, actions) {
    let tree = [];

    actions.innerHTML = '';
    const newBtn = el('button', { className: 'btn btn-primary', on: { click: () => openCategoryForm() } }, [
        el('span', { className: 'icon icon-plus' }),
        el('span', { text: 'Nova categoria' }),
    ]);
    actions.appendChild(newBtn);

    root.innerHTML = '<div class="loading">Carregando</div>';

    async function reload() {
        try {
            tree = await api('/api/categories.php?tree=1');
            render();
        } catch (e) {
            root.innerHTML = `<div class="empty"><p>${escapeHtml(e.message)}</p></div>`;
        }
    }

    function render() {
        root.innerHTML = '';
        const count = countNodes(tree);
        root.appendChild(el('p', { className: 'page-subtitle', text: `${count} categoria(s) · Hierárquicas (até 2 níveis)` }));

        const card = el('div', { className: 'card' });
        const body = el('div', { className: 'card-body' });
        if (tree.length === 0) {
            body.appendChild(el('div', { className: 'empty', text: 'Nenhuma categoria cadastrada.' }));
        } else {
            const ul = el('ul', { style: 'list-style:none;padding:0;margin:0' });
            for (const node of tree) {
                ul.appendChild(renderTreeNode(node, 0));
            }
            body.appendChild(ul);
        }
        card.appendChild(body);
        root.appendChild(card);
    }

    function renderTreeNode(node, depth) {
        const li = el('li', { style: `padding-left:${depth * 20}px` });
        const row = el('div', { style: 'display:flex;align-items:center;gap:10px;padding:8px;border-radius:6px' });
        row.addEventListener('mouseenter', () => row.style.background = '#f0f4f1');
        row.addEventListener('mouseleave', () => row.style.background = '');

        const kindInfo = kindInfoMap[node.node.kind] || kindInfoMap.expense;
        row.appendChild(el('div', { className: `account-icon ${kindInfo.cls}`, style: 'width:24px;height:24px;font-size:12px', text: kindInfo.icon }));
        row.appendChild(el('span', { text: node.node.name, style: 'flex:1;font-weight:500' }));
        row.appendChild(el('span', { className: 'badge', text: kindInfo.label }));

        if (depth === 0) {
            row.appendChild(el('button', { className: 'btn btn-sm', text: '+ Sub', on: { click: () => openCategoryForm(node.node) } }));
        }
        if (!node.node.is_system) {
            row.appendChild(el('button', { className: 'btn-icon danger', on: { click: () => confirmDeleteCategory(node.node) } }, [el('span', { className: 'icon icon-trash' })]));
        }

        li.appendChild(row);
        if (node.children && node.children.length > 0) {
            const subUl = el('ul', { style: 'list-style:none;padding:0;margin:0' });
            for (const child of node.children) {
                subUl.appendChild(renderTreeNode(child, depth + 1));
            }
            li.appendChild(subUl);
        }
        return li;
    }

    function openCategoryForm(parent = null) {
        const form = el('div');
        const nameInput = buildInput('text', '', 'Ex.: Supermercado');
        const kindSel = buildSelect(
            [{ value: 'income', label: 'Receita' }, { value: 'expense', label: 'Despesa' }, { value: 'transfer', label: 'Transferência' }],
            parent ? parent.kind : 'expense',
        );
        if (parent) kindSel.disabled = true;

        form.appendChild(field('Nome *', nameInput));
        if (!parent) {
            form.appendChild(field('Tipo', kindSel));
        } else {
            form.appendChild(el('p', { text: `Subcategoria de "${parent.name}". Herdará o tipo (${kindLabel(parent.kind)}).`, style: 'font-size:12px;color:#5c6b65' }));
        }

        const saveBtn = el('button', { className: 'btn btn-primary', text: 'Criar' });
        const cancelBtn = el('button', { className: 'btn', text: 'Cancelar' });
        const { close } = openModal(parent ? `Nova subcategoria em "${parent.name}"` : 'Nova categoria', form, [cancelBtn, saveBtn]);
        cancelBtn.addEventListener('click', close);
        saveBtn.addEventListener('click', async () => {
            if (!nameInput.value.trim()) { toast('Informe o nome.', 'error'); return; }
            saveBtn.disabled = true;
            try {
                await api('/api/categories.php', { method: 'POST', body: {
                    name: nameInput.value.trim(),
                    parent_id: parent ? parent.id : null,
                    kind: parent ? parent.kind : kindSel.value,
                } });
                toast('Categoria criada.', 'success');
                close();
                reload();
            } catch (e) {
                toast(e.message, 'error');
                saveBtn.disabled = false;
            }
        });
        nameInput.focus();
    }

    function confirmDeleteCategory(node) {
        const body = el('div', {}, [
            el('p', { text: `Excluir a categoria "${node.name}"?` }),
            el('p', { text: 'Categorias com subitens ou usadas em transações não podem ser removidas (RESTRICT).', attrs: { style: 'font-size:12px;color:#5c6b65;margin-top:8px' } }),
        ]);
        const confirmBtn = el('button', { className: 'btn btn-danger', text: 'Excluir' });
        const cancelBtn = el('button', { className: 'btn', text: 'Cancelar' });
        const { close } = openModal('Excluir categoria?', body, [cancelBtn, confirmBtn]);
        cancelBtn.addEventListener('click', close);
        confirmBtn.addEventListener('click', async () => {
            confirmBtn.disabled = true;
            try {
                await api('/api/categories.php?id=' + node.id, { method: 'DELETE' });
                toast('Categoria excluída.', 'success');
                close();
                reload();
            } catch (e) {
                toast(e.message, 'error');
                confirmBtn.disabled = false;
            }
        });
    }

    reload();
}

// ============================================================
// PAYEES
// ============================================================
async function PayeesView(root, actions) {
    let payees = [];
    let categories = [];

    actions.innerHTML = '';
    const newBtn = el('button', { className: 'btn btn-primary', on: { click: () => openPayeeForm() } }, [
        el('span', { className: 'icon icon-plus' }),
        el('span', { text: 'Novo beneficiário' }),
    ]);
    actions.appendChild(newBtn);

    root.innerHTML = '<div class="loading">Carregando</div>';

    async function reload() {
        try {
            const [p, c] = await Promise.all([api('/api/payees.php'), api('/api/categories.php')]);
            payees = p;
            categories = c;
            render();
        } catch (e) {
            root.innerHTML = `<div class="empty"><p>${escapeHtml(e.message)}</p></div>`;
        }
    }

    function render() {
        root.innerHTML = '';
        root.appendChild(el('p', { className: 'page-subtitle', text: `${payees.length} cadastrado(s)` }));

        const card = el('div', { className: 'card' });
        const body = el('div', { className: 'card-body' });
        if (payees.length === 0) {
            body.appendChild(el('div', { className: 'empty', text: 'Nenhum beneficiário.' }));
        } else {
            const grid = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px' });
            for (const p of payees) {
                const item = el('div', { style: 'display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--border);border-radius:6px' }, [
                    el('div', { style: 'width:32px;height:32px;border-radius:50%;background:var(--primary-light);color:var(--text);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px', text: p.name.charAt(0).toUpperCase() }),
                    el('div', { style: 'flex:1;min-width:0' }, [
                        el('div', { style: 'font-weight:500;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis', text: p.name }),
                        el('div', { style: 'font-size:11px;color:var(--text-muted)', text: p.default_category_name || 'Sem categoria padrão' }),
                    ]),
                    el('span', { style: 'display:inline-flex;gap:2px;flex-shrink:0' }, [
                        el('button', {
                            className: 'btn-icon',
                            attrs: { type: 'button', title: 'Editar' },
                            on: { click: () => openPayeeForm(p) },
                        }, [el('span', { className: 'icon icon-edit' })]),
                        el('button', {
                            className: 'btn-icon danger',
                            attrs: { type: 'button', title: 'Excluir' },
                            on: { click: () => confirmDelete(p) },
                        }, [el('span', { className: 'icon icon-trash' })]),
                    ]),
                ]);
                grid.appendChild(item);
            }
            body.appendChild(grid);
        }
        card.appendChild(body);
        root.appendChild(card);
    }

    function openPayeeForm(editing = null) {
        const isEdit = !!(editing && editing.id);
        const form = el('div');
        const nameInput = buildInput('text', editing?.name ?? '', 'Ex.: Padaria São João');
        const catSel = buildSelect(
            [{ value: '', label: '— Sem categoria padrão —' }, ...categories.map(c => ({ value: c.id, label: c.parent_name ? `${c.parent_name} › ${c.name}` : c.name }))],
            editing?.default_category_id ?? '',
        );
        const notesInput = buildInput('text', editing?.notes ?? '', 'CNPJ, observações...');

        form.appendChild(field('Nome *', nameInput));
        form.appendChild(field('Categoria padrão', catSel));
        form.appendChild(field('Notas', notesInput));

        const saveBtn = el('button', { className: 'btn btn-primary', text: isEdit ? 'Salvar' : 'Criar' });
        const cancelBtn = el('button', { className: 'btn', text: 'Cancelar' });
        const { close } = openModal(isEdit ? 'Editar beneficiário' : 'Novo beneficiário', form, [cancelBtn, saveBtn]);
        cancelBtn.addEventListener('click', close);
        saveBtn.addEventListener('click', async () => {
            if (!nameInput.value.trim()) { toast('Informe o nome.', 'error'); return; }
            saveBtn.disabled = true;
            const payload = {
                name: nameInput.value.trim(),
                default_category_id: catSel.value ? Number(catSel.value) : null,
                notes: notesInput.value || null,
            };
            try {
                if (isEdit) {
                    await api('/api/payees.php?id=' + editing.id, { method: 'PATCH', body: payload });
                    toast('Beneficiário atualizado.', 'success');
                } else {
                    await api('/api/payees.php', { method: 'POST', body: payload });
                    toast('Beneficiário criado.', 'success');
                }
                close();
                reload();
            } catch (e) {
                toast(e.message, 'error');
                saveBtn.disabled = false;
            }
        });
        nameInput.focus();
    }

    function confirmDelete(p) {
        const body = el('div', {}, [
            el('p', { text: `Excluir beneficiário "${p.name}"?` }),
            el('p', { text: 'Transações que o referenciam terão payee_id setado para NULL.', attrs: { style: 'font-size:12px;color:#5c6b65;margin-top:8px' } }),
        ]);
        const confirmBtn = el('button', { className: 'btn btn-danger', text: 'Excluir' });
        const cancelBtn = el('button', { className: 'btn', text: 'Cancelar' });
        const { close } = openModal('Excluir beneficiário?', body, [cancelBtn, confirmBtn]);
        cancelBtn.addEventListener('click', close);
        confirmBtn.addEventListener('click', async () => {
            confirmBtn.disabled = true;
            try {
                await api('/api/payees.php?id=' + p.id, { method: 'DELETE' });
                toast('Beneficiário excluído.', 'success');
                close();
                reload();
            } catch (e) {
                toast(e.message, 'error');
                confirmBtn.disabled = false;
            }
        });
    }

    reload();
}

// ============================================================
// INSTITUTIONS
// ============================================================
async function InstitutionsView(root, actions) {
    let items = [];

    actions.innerHTML = '';
    const newBtn = el('button', { className: 'btn btn-primary', on: { click: () => openForm() } }, [
        el('span', { className: 'icon icon-plus' }),
        el('span', { text: 'Nova instituição' }),
    ]);
    actions.appendChild(newBtn);

    root.innerHTML = '<div class="loading">Carregando</div>';

    async function reload() {
        try {
            items = await api('/api/institutions.php');
            render();
        } catch (e) {
            root.innerHTML = `<div class="empty"><p>${escapeHtml(e.message)}</p></div>`;
        }
    }

    function render() {
        root.innerHTML = '';
        root.appendChild(el('p', { className: 'page-subtitle', text: `${items.length} cadastrada(s)` }));

        const card = el('div', { className: 'card' });
        const body = el('div', { className: 'card-body' });
        if (items.length === 0) {
            body.appendChild(el('div', { className: 'empty', text: 'Nenhuma instituição cadastrada.' }));
        } else {
            const ul = el('ul', { style: 'list-style:none;padding:0;margin:0' });
            for (const i of items) {
                const li = el('li', { style: 'display:flex;align-items:center;gap:12px;padding:10px;border-bottom:1px solid var(--border)' }, [
                    el('div', { className: 'account-icon bank', text: 'B' }),
                    el('div', { style: 'flex:1' }, [
                        el('div', { style: 'font-weight:500', text: i.name }),
                        el('div', { style: 'font-size:11px;color:var(--text-muted)', text: `${i.account_count} conta(s)${i.notes ? ' · ' + i.notes : ''}` }),
                    ]),
                    el('button', { className: 'btn-icon danger', on: { click: () => confirmDelete(i) } }, [el('span', { className: 'icon icon-trash' })]),
                ]);
                ul.appendChild(li);
            }
            body.appendChild(ul);
        }
        card.appendChild(body);
        root.appendChild(card);
    }

    function openForm() {
        const form = el('div');
        const nameInput = buildInput('text', '', 'Ex.: Banco do Brasil');
        const notesInput = buildInput('text', '', 'Código, observações...');

        form.appendChild(field('Nome *', nameInput));
        form.appendChild(field('Notas', notesInput));

        const saveBtn = el('button', { className: 'btn btn-primary', text: 'Criar' });
        const cancelBtn = el('button', { className: 'btn', text: 'Cancelar' });
        const { close } = openModal('Nova instituição', form, [cancelBtn, saveBtn]);
        cancelBtn.addEventListener('click', close);
        saveBtn.addEventListener('click', async () => {
            if (!nameInput.value.trim()) { toast('Informe o nome.', 'error'); return; }
            saveBtn.disabled = true;
            try {
                await api('/api/institutions.php', { method: 'POST', body: { name: nameInput.value.trim(), notes: notesInput.value || null } });
                toast('Instituição criada.', 'success');
                close();
                reload();
            } catch (e) {
                toast(e.message, 'error');
                saveBtn.disabled = false;
            }
        });
        nameInput.focus();
    }

    function confirmDelete(i) {
        const body = el('div', {}, [
            el('p', { text: `Excluir instituição "${i.name}"?` }),
            el('p', { text: 'Contas associadas terão institution_id setado para NULL.', attrs: { style: 'font-size:12px;color:#5c6b65;margin-top:8px' } }),
        ]);
        const confirmBtn = el('button', { className: 'btn btn-danger', text: 'Excluir' });
        const cancelBtn = el('button', { className: 'btn', text: 'Cancelar' });
        const { close } = openModal('Excluir instituição?', body, [cancelBtn, confirmBtn]);
        cancelBtn.addEventListener('click', close);
        confirmBtn.addEventListener('click', async () => {
            confirmBtn.disabled = true;
            try {
                await api('/api/institutions.php?id=' + i.id, { method: 'DELETE' });
                toast('Instituição excluída.', 'success');
                close();
                reload();
            } catch (e) {
                toast(e.message, 'error');
                confirmBtn.disabled = false;
            }
        });
    }

    reload();
}

// ============================================================
// Helpers
// ============================================================
function escapeHtml(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function currentMonthIso() {
    const now = new Date();
    return `${now.getUTCFullYear()}-${(now.getUTCMonth() + 1).toString().padStart(2, '0')}`;
}

function monthOptions(n) {
    const out = [];
    const now = new Date();
    for (let i = 0; i < n; i++) {
        const d = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() - i, 1));
        const value = `${d.getUTCFullYear()}-${(d.getUTCMonth() + 1).toString().padStart(2, '0')}`;
        const label = new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(d);
        out.push({ value, label: label.charAt(0).toUpperCase() + label.slice(1) });
    }
    return out;
}

function todayPtBr() {
    const now = new Date();
    return `${now.getUTCDate().toString().padStart(2, '0')}/${(now.getUTCMonth() + 1).toString().padStart(2, '0')}/${now.getUTCFullYear()}`;
}

/** Hoje em YYYY-MM-DD (UTC). */
function todayIso() {
    const now = new Date();
    return `${now.getUTCFullYear()}-${(now.getUTCMonth() + 1).toString().padStart(2, '0')}-${now.getUTCDate().toString().padStart(2, '0')}`;
}

/** Desloca hoje em N dias (UTC), retorna YYYY-MM-DD. */
function shiftIsoDays(delta) {
    const now = new Date();
    const d = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() + delta));
    return `${d.getUTCFullYear()}-${(d.getUTCMonth() + 1).toString().padStart(2, '0')}-${d.getUTCDate().toString().padStart(2, '0')}`;
}

/**
 * Converte DD/MM/YYYY ou YYYY-MM-DD para YYYY-MM-DD.
 * Retorna '' se inválido.
 */
function toIsoDate(value) {
    if (!value) return '';
    const s = String(value).trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
    const m = s.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (m) return `${m[3]}-${m[2]}-${m[1]}`;
    return '';
}

/**
 * Avança N meses a partir de DD/MM/YYYY ou YYYY-MM-DD.
 * Mantém o dia quando possível (31/01 → 28/02 ou 29/02).
 * Retorna DD/MM/YYYY (formato aceito pela API).
 */
function addMonthsToDate(value, monthsToAdd) {
    const iso = toIsoDate(value);
    if (!iso) return '';
    const [y, m, d] = iso.split('-').map(Number);
    const base = new Date(Date.UTC(y, m - 1, 1));
    base.setUTCMonth(base.getUTCMonth() + monthsToAdd);
    const year = base.getUTCFullYear();
    const month = base.getUTCMonth(); // 0-11
    const lastDay = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
    const day = Math.min(d, lastDay);
    return `${String(day).padStart(2, '0')}/${String(month + 1).padStart(2, '0')}/${year}`;
}

/**
 * Normaliza valor digitado (pt-BR ou en-US) para decimal canônico "0.02".
 * Exemplos: "0,02" → "0.02"; "0.02" → "0.02"; "1.181,47" → "1181.47"; "0,2" → "0.20".
 */
function normalizeMoneyInput(raw) {
    let s = String(raw ?? '').trim();
    s = s.replace(/R\$\s?/gi, '').replace(/\s/g, '');
    s = s.replace(/^[+]/, '');
    if (s.startsWith('-')) {
        throw new Error('Informe o valor sem sinal; use Despesa/Receita.');
    }
    if (!s) throw new Error('Informe o valor.');
    if (!/^[0-9.,]+$/.test(s)) throw new Error('Valor inválido.');

    const hasComma = s.includes(',');
    const hasDot = s.includes('.');
    let intPart;
    let fracPart;

    if (hasComma && hasDot) {
        // Último separador é o decimal.
        const lastComma = s.lastIndexOf(',');
        const lastDot = s.lastIndexOf('.');
        if (lastComma > lastDot) {
            // 1.234,56
            intPart = s.slice(0, lastComma).replace(/\./g, '');
            fracPart = s.slice(lastComma + 1);
        } else {
            // 1,234.56
            intPart = s.slice(0, lastDot).replace(/,/g, '');
            fracPart = s.slice(lastDot + 1);
        }
    } else if (hasComma) {
        // 0,02 ou 1181,47
        const parts = s.split(',');
        if (parts.length !== 2) throw new Error('Valor inválido.');
        intPart = parts[0];
        fracPart = parts[1];
    } else if (hasDot) {
        // 0.02 ou 1181.47 — ponto como decimal (não milhar com 1 grupo só)
        const parts = s.split('.');
        if (parts.length === 2 && parts[1].length <= 2) {
            intPart = parts[0];
            fracPart = parts[1];
        } else if (parts.length > 1 && parts.every((p, i) => i === 0 ? p.length >= 1 : p.length === 3)) {
            // 1.000.000 → inteiro
            intPart = parts.join('');
            fracPart = '00';
        } else {
            throw new Error('Valor inválido. Use 0,02 ou 1.181,47');
        }
    } else {
        intPart = s;
        fracPart = '00';
    }

    if (!/^\d+$/.test(intPart) || !/^\d+$/.test(fracPart)) {
        throw new Error('Valor inválido.');
    }
    if (fracPart.length > 2) {
        throw new Error('No máximo 2 casas decimais.');
    }
    // "2" → "20" (décimos); "02" permanece "02"
    fracPart = fracPart.padEnd(2, '0');
    // Remove zeros à esquerda do inteiro, mantém ao menos "0"
    intPart = String(parseInt(intPart, 10));
    if (Number.isNaN(Number(intPart))) throw new Error('Valor inválido.');
    return `${intPart}.${fracPart}`;
}

/**
 * Status do lançamento (valores no DB inalterados):
 *   none        → Previsão   — planejado, ainda não saiu/entrou no banco
 *   cleared     → No banco   — já lançado na conta bancária
 *   reconciled  → Conciliada — conferido com o extrato
 */
function statusLabel(s) {
    return { none: 'Previsão', cleared: 'No banco', reconciled: 'Conciliada' }[s] || s;
}

function statusTitle(s) {
    return {
        none: 'Previsão: ainda não lançado no banco. Clique para marcar como No banco.',
        cleared: 'Já lançado no banco. Clique para marcar como Conciliada.',
        reconciled: 'Conciliada com o extrato. Clique para voltar a Previsão.',
    }[s] || 'Clique para alterar o status';
}

function typeLabel(t) {
    return { bank: 'Conta bancária', credit_card: 'Cartão de crédito', cash: 'Dinheiro', investment: 'Investimento', loan: 'Empréstimo', asset: 'Ativo', liability: 'Passivo' }[t] || t;
}

function typeEmoji(t) {
    return { bank: '🏦', credit_card: '💳', cash: '💵', investment: '📈', loan: '⚠', asset: '🏠', liability: '⚠' }[t] || '💰';
}

function kindInfoMapOf() {
    return {
        income: { label: 'Receita', icon: '↑', cls: 'income' },
        expense: { label: 'Despesa', icon: '↓', cls: 'expense' },
        transfer: { label: 'Transferência', icon: '⇄', cls: 'asset' },
    };
}
const kindInfoMap = kindInfoMapOf();

function kindLabel(k) {
    return kindInfoMap[k]?.label || k;
}

function countNodes(nodes) {
    let n = 0;
    for (const node of nodes) {
        n += 1;
        if (node.children) n += countNodes(node.children);
    }
    return n;
}
