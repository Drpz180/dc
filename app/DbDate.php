<?php
// app/DbDate.php — filtros de data compatíveis com PostgreSQL

class DbDate
{
    /**
     * Retorna [sql_fragment, params] para filtrar orders por período.
     */
    public static function orderPeriodFilter(string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        switch ($period) {
            case 'today':
                return ['created_at::date = CURRENT_DATE', []];
            case 'yesterday':
                return ["created_at::date = CURRENT_DATE - INTERVAL '1 day'", []];
            case 'week':
                return [
                    "created_at >= date_trunc('week', CURRENT_DATE::timestamp)
                     AND created_at < date_trunc('week', CURRENT_DATE::timestamp) + INTERVAL '1 week'",
                    [],
                ];
            case 'month':
                return ["date_trunc('month', created_at) = date_trunc('month', CURRENT_DATE::timestamp)", []];
            case 'custom':
                if ($startDate && $endDate) {
                    return ['created_at::date BETWEEN ?::date AND ?::date', [$startDate, $endDate]];
                }
                return ['created_at::date = CURRENT_DATE', []];
            default:
                return ['created_at::date = CURRENT_DATE', []];
        }
    }
}
