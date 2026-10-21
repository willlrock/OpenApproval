import { normalizeMoneyInput } from './draftInputs';

export type DraftErrors = Record<string, string[]>;
type Option = { id: number };
export type DraftValidationContext = {
    projects: Option[];
    suppliers: Option[];
    approvers: Option[];
    supplyChain: Option[];
    today?: string;
};

// Mirrors the editable rules in ApprovalRequestCreation; the server remains authoritative.
export function validateDraft(
    p: any,
    context: DraftValidationContext,
): DraftErrors {
    const errors: DraftErrors = {};
    const add = (key: string, message: string) => {
        errors[key] ??= [message];
    };
    const blank = (value: unknown) =>
        value == null || String(value).trim() === '';
    const supply = p.type === 'supply';
    const today = context.today ?? new Date().toLocaleDateString('sv-SE');
    const text = (
        key: string,
        value: unknown,
        label: string,
        max: number,
        required = false,
    ) => {
        if (required && blank(value)) add(key, `Заполните поле «${label}».`);
        else if (!blank(value) && String(value).length > max)
            add(key, `${label}: не более ${max} символов.`);
    };
    const number = (
        key: string,
        value: unknown,
        label: string,
        min: number,
        max: number,
        decimals: number,
    ) => {
        if (blank(value)) return add(key, `Укажите ${label.toLowerCase()}.`);
        const n = Number(value);
        if (!Number.isFinite(n) || n < min || n > max)
            add(key, `${label}: число от ${min} до ${max}.`);
        else if (
            !new RegExp(
                `^[+-]?(?:\\d+(?:\\.\\d{0,${decimals}})?|\\.\\d{1,${decimals}})$`,
            ).test(String(value))
        )
            add(key, `${label}: не более ${decimals} знаков после запятой.`);
    };
    const date = (key: string, value: unknown, required: boolean) => {
        if (blank(value)) {
            if (required) add(key, 'Укажите срок.');
            return;
        }
        const s = String(value);
        const parsed = new Date(s + 'T00:00:00Z');
        if (
            !/^\d{4}-\d{2}-\d{2}$/.test(s) ||
            !Number.isFinite(parsed.getTime()) ||
            parsed.toISOString().slice(0, 10) !== s
        )
            add(key, 'Укажите корректную дату.');
        else if (s < today) add(key, 'Выберите сегодняшний или будущий срок.');
    };
    if (!['approval', 'supply'].includes(p.type))
        add('type', 'Выберите тип заявки.');
    if (!context.projects.some((x) => x.id === Number(p.project_id)))
        add('project_id', 'Выберите доступный проект.');
    date('deadline_date', p.deadline_date, supply);
    if (!supply) {
        if (!['UZS', 'USD', 'EUR', 'RUB'].includes(p.currency))
            add('currency', 'Выберите валюту.');
        if (!['cash', 'bank'].includes(p.transaction_type))
            add('transaction_type', 'Выберите способ оплаты.');
        if (
            !blank(p.supplier_id) &&
            !context.suppliers.some((x) => x.id === Number(p.supplier_id))
        )
            add('supplier_id', 'Выберите доступного поставщика.');
        text(
            'supplier_name',
            p.supplier_name,
            'Поставщик',
            255,
            blank(p.supplier_id),
        );
        text('supplier_inn', p.supplier_inn, 'ИНН', 30);
    }
    const items = Array.isArray(p.items) ? p.items : [];
    if (!items.length || items.length > 100)
        add('items', 'Добавьте от 1 до 100 позиций.');
    let total = 0;
    items.forEach((item: any, i: number) => {
        text(`items.${i}.name`, item.name, 'Наименование позиции', 255, true);
        text(`items.${i}.description`, item.description, 'Описание', 2000);
        number(
            `items.${i}.quantity`,
            item.quantity,
            'Количество',
            0.001,
            100000000,
            3,
        );
        if (!supply) {
            number(
                `items.${i}.price_per_unit`,
                normalizeMoneyInput(item.price_per_unit),
                'Цена',
                0,
                1000000000,
                2,
            );
            total += Math.round(
                Number(item.quantity) *
                    Number(normalizeMoneyInput(item.price_per_unit)) *
                    100,
            );
        }
    });
    if (
        !supply &&
        (!Number.isFinite(total) || total <= 0 || total > 999999999999999)
    )
        add(
            'items',
            'Итоговая сумма должна быть больше нуля и не превышать допустимый предел.',
        );
    const chain = supply
        ? context.supplyChain.map((x) => x.id)
        : Array.isArray(p.approvers)
          ? p.approvers.map(Number)
          : [];
    if (
        !chain.length ||
        (!supply && chain.length > 30) ||
        new Set(chain).size !== chain.length ||
        (!supply &&
            chain.some(
                (id: number) => !context.approvers.some((x) => x.id === id),
            ))
    )
        add(
            'approvers',
            supply
                ? 'Маршрут снабжения недоступен. Попросите администратора настроить проект.'
                : 'Выберите действующих согласующих проекта без повторений.',
        );
    if (!supply) {
        const tranches = Array.isArray(p.tranches) ? p.tranches : [];
        if (tranches.length > 50)
            add('tranches', 'Допускается не более 50 платежей.');
        tranches.forEach((t: any, i: number) => {
            number(
                `tranches.${i}.amount`,
                normalizeMoneyInput(t.amount),
                'Сумма платежа',
                0.01,
                9999999999999,
                2,
            );
            date(`tranches.${i}.due_date`, t.due_date, true);
            text(`tranches.${i}.comment`, t.comment, 'Комментарий', 1000);
        });
        if (
            tranches.length &&
            tranches.reduce(
                (sum: number, t: any) =>
                    sum +
                    Math.round(Number(normalizeMoneyInput(t.amount)) * 100),
                0,
            ) !== total
        )
            add(
                'tranches',
                'Сумма платежей должна точно совпадать с суммой позиций.',
            );
    }
    return errors;
}
