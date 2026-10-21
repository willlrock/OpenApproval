<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Plus, ChevronDown, Receipt, Package, FileText } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
const pending = ref(false);
function create(type: string) {
    if (pending.value) return;
    pending.value = true;
    router.post(
        '/request-drafts',
        { type },
        { onFinish: () => (pending.value = false) },
    );
}
</script>
<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child
            ><Button :disabled="pending"
                ><Plus class="size-4" />{{ pending ? 'Создаём…' : 'Создать'
                }}<ChevronDown class="size-4" /></Button
        ></DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-72">
            <DropdownMenuItem @select="create('approval')"
                ><Receipt class="size-4" />
                <div>
                    <div>Расходная заявка</div>
                    <div class="text-xs text-muted-foreground">
                        Оплата товаров или услуг
                    </div>
                </div></DropdownMenuItem
            >
            <DropdownMenuItem @select="create('supply')"
                ><Package class="size-4" />
                <div>
                    <div>Заявка на снабжение</div>
                    <div class="text-xs text-muted-foreground">
                        Закупка и поставка
                    </div>
                </div></DropdownMenuItem
            >
            <DropdownMenuItem as-child
                ><Link href="/management-drafts/create"
                    ><FileText class="size-4" />
                    <div>
                        <div>Приказ или постановление</div>
                        <div class="text-xs text-muted-foreground">
                            Документ на подпись
                        </div>
                    </div></Link
                ></DropdownMenuItem
            >
        </DropdownMenuContent>
    </DropdownMenu>
</template>
