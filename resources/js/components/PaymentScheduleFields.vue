<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const rows = defineModel<
    { amount: string; due_date: string; comment: string }[]
>({ required: true });
defineProps<{ currency: string }>();
</script>

<template>
    <div class="space-y-3">
        <Label>График платежей</Label>
        <p class="text-sm text-muted-foreground">
            Без графика будет создан один платёж на всю сумму со сроком оплаты
            заявки.
        </p>
        <div
            v-for="(row, index) in rows"
            :key="index"
            class="grid gap-2 rounded border p-3 sm:grid-cols-4"
        >
            <label class="text-sm"
                >Сумма, {{ currency
                }}<Input
                    v-model="row.amount"
                    type="number"
                    min="0.01"
                    step="0.01"
                    required
            /></label>
            <label class="text-sm"
                >Дата платежа<Input v-model="row.due_date" type="date" required
            /></label>
            <label class="text-sm"
                >Комментарий<Input v-model="row.comment" maxlength="1000"
            /></label>
            <Button
                type="button"
                variant="outline"
                class="self-end"
                @click="rows.splice(index, 1)"
                >Удалить платёж {{ index + 1 }}</Button
            >
        </div>
        <Button
            v-if="rows.length < 50"
            type="button"
            variant="outline"
            @click="rows.push({ amount: '', due_date: '', comment: '' })"
            >Добавить платёж</Button
        >
    </div>
</template>
