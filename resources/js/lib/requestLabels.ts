export const requestStatusLabels: Record<string, string> = {
    draft: 'Черновик',
    approval_paused: 'Согласование приостановлено',
    pending_approval: 'На согласовании',
    approved: 'Утверждена',
    revision_requested: 'На доработке',
    pending_supply: 'В снабжении',
    rejected: 'Отклонена',
    cancelled: 'Отменена',
    paid: 'Оплачена',
};

export const requestTypeLabels: Record<string, string> = {
    approval: 'Расходная заявка',
    expense: 'Расходная заявка',
    supply: 'Заявка на снабжение',
    management_document: 'Документ руководства',
};
