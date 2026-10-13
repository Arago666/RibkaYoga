export interface Subscription {
  id: string | null;
  plan: 'basic' | 'pro';
  plan_name: string;
  status: string;
  ends_at: string | null;
  cancel_at_period_end: boolean;
  revision: number;
  limits: { asanas: number | null; practices: number | null; mesocycles: number | null };
  payment_provider_connected: boolean;
  payments: { id: string; amount_minor: number; currency: string; status: string; paid_at: string | null }[];
}
export const subscriptionStatuses: Record<string, string> = {
  active: 'Доступ предоставлен',
  trialing: 'Пробный период',
  expired: 'Срок закончился, действует базовый тариф',
  basic: 'Базовый доступ',
  cancelled: 'Продление отменено',
};
export function localDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU') : 'Без ограничения срока';
}
