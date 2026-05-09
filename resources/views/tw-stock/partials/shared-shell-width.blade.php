        :root {
            --tw-stock-shell-max: 1960px;
            --tw-stock-shell-gutter: 12px;
        }

        .shell {
            width: min(var(--tw-stock-shell-max), calc(100vw - var(--tw-stock-shell-gutter)));
        }

        .pagination,
        .pager {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .pagination .count-text {
            flex: 1 1 100%;
            color: var(--muted, #64748b);
            text-align: center;
            font-size: 13px;
            font-weight: 800;
        }

        .tw-stock-pagination {
            display: flex;
            width: 100%;
            justify-content: center;
        }

        .tw-stock-pagination__list {
            display: inline-flex;
            overflow: hidden;
            align-items: stretch;
            border: 1px solid var(--line, #d7e0eb);
            border-radius: 7px;
            background: #ffffff;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.08);
        }

        .tw-stock-pagination__item {
            display: inline-flex;
            width: 49px;
            height: 49px;
            align-items: center;
            justify-content: center;
            border-right: 1px solid var(--line, #d7e0eb);
            color: var(--blue, #2563eb);
            background: #ffffff;
            font-size: 18px;
            font-weight: 850;
            line-height: 1;
            text-decoration: none;
            font-variant-numeric: tabular-nums;
            transition: background-color 140ms ease, color 140ms ease;
        }

        .tw-stock-pagination__item:last-child {
            border-right: 0;
        }

        .tw-stock-pagination__item:hover,
        .tw-stock-pagination__item:focus-visible {
            color: #ffffff;
            background: #2f7dbc;
            outline: none;
        }

        .tw-stock-pagination__item.active {
            color: #ffffff;
            background: #2f7dbc;
        }

        .tw-stock-pagination__item.disabled {
            color: #64748b;
            background: #ffffff;
            cursor: default;
        }

        @media (max-width: 640px) {
            .tw-stock-pagination {
                justify-content: flex-start;
                overflow-x: auto;
                padding-bottom: 4px;
            }

            .tw-stock-pagination__list {
                flex: 0 0 auto;
            }

            .tw-stock-pagination__item {
                width: 44px;
                height: 44px;
                font-size: 16px;
            }
        }
