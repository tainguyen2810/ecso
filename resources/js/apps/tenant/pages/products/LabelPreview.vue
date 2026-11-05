<script setup lang="ts">
import { computed } from 'vue';
import { labelParts, MM, type LabelItem, type LabelOptions, type Paper } from './labels';
import type { FontMetrics } from './fontMetrics';

/** One label drawn from the same parts as the PDF; `scale` is px per pt. */
const props = defineProps<{ item: LabelItem; options: LabelOptions; paper: Paper; metrics: FontMetrics; scale: number; fontFamily: string }>();

const parts = computed(() => labelParts(props.item, props.options, props.paper, props.metrics));
const k = computed(() => props.scale);

function textStyle(text: string, cx: number, y: number, size: number, bold: boolean) {
    const m = props.metrics;
    const w = m.width(text, size);
    return {
        left: `${(cx - w / 2) * k.value}px`,
        top: `${(y - m.ascent * size) * k.value}px`,
        fontSize: `${size * k.value}px`,
        lineHeight: `${(m.ascent + m.descent) * size * k.value}px`,
        fontWeight: bold ? 700 : 400,
        fontFamily: props.fontFamily,
    };
}
</script>

<template>
    <div class="relative overflow-hidden bg-white text-black" :style="{ width: `${paper.width * MM * k}px`, height: `${paper.height * MM * k}px` }">
        <template v-for="(p, i) in parts" :key="i">
            <svg
                v-if="p.kind === 'bars'"
                class="absolute"
                :style="{ left: `${p.x * k}px`, top: `${p.y * k}px`, width: `${p.width * k}px`, height: `${p.height * k}px` }"
                :viewBox="`0 0 ${p.width / p.module} 1`"
                preserveAspectRatio="none"
                shape-rendering="crispEdges"
            >
                <rect v-for="(b, j) in p.bars" :key="j" :x="b.x" y="0" :width="b.width" height="1" fill="#000" />
            </svg>
            <span v-else class="absolute whitespace-nowrap" :style="textStyle(p.text, p.cx, p.y, p.size, p.bold)">{{ p.text }}</span>
        </template>
    </div>
</template>
