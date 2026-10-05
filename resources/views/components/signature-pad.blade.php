@props(['model', 'initial' => '', 'label' => 'Signature', 'hint' => 'Sign with a finger or mouse'])

<div>
    <div class="mb-1.5 flex items-center justify-between">
        <p class="label" style="margin-bottom: 0">{{ $label }}</p>
    </div>
    <div wire:ignore
         data-initial="{{ $initial }}"
         x-data="{
            drawing: false,
            empty: true,
            ctx: null,
            init() {
                const c = this.$refs.c;
                this.ctx = c.getContext('2d');
                this.ctx.lineWidth = 3;
                this.ctx.lineCap = 'round';
                this.ctx.lineJoin = 'round';
                this.ctx.strokeStyle = '#18181b';
                const initial = this.$el.dataset.initial;
                if (initial) {
                    const img = new Image();
                    img.onload = () => { this.ctx.drawImage(img, 0, 0, c.width, c.height); this.empty = false; };
                    img.src = initial;
                }
            },
            pos(e) {
                const c = this.$refs.c;
                const r = c.getBoundingClientRect();
                return { x: (e.clientX - r.left) * c.width / r.width, y: (e.clientY - r.top) * c.height / r.height };
            },
            start(e) {
                e.preventDefault();
                this.$refs.c.setPointerCapture(e.pointerId);
                this.drawing = true;
                const p = this.pos(e);
                this.ctx.beginPath();
                this.ctx.moveTo(p.x, p.y);
                this.ctx.lineTo(p.x + 0.1, p.y + 0.1);
                this.ctx.stroke();
                this.empty = false;
            },
            move(e) {
                if (!this.drawing) return;
                e.preventDefault();
                const p = this.pos(e);
                this.ctx.lineTo(p.x, p.y);
                this.ctx.stroke();
            },
            end() {
                if (!this.drawing) return;
                this.drawing = false;
                this.$wire.$set('{{ $model }}', this.$refs.c.toDataURL('image/png'), false);
            },
            clear() {
                const c = this.$refs.c;
                this.ctx.clearRect(0, 0, c.width, c.height);
                this.empty = true;
                this.$wire.$set('{{ $model }}', '', false);
            }
         }">
        <div class="rf-sign">
            <canvas x-ref="c" width="800" height="360"
                    style="width: 100%; height: 180px; touch-action: none; cursor: crosshair; display: block; position: relative; z-index: 1;"
                    @pointerdown="start($event)"
                    @pointermove="move($event)"
                    @pointerup="end()"
                    @pointercancel="end()"></canvas>
            <span class="rf-sign-line"></span>
            <span x-show="empty" class="rf-sign-hint">{{ $hint }}</span>
        </div>
        <div class="mt-2 flex items-center justify-between">
            <span x-show="!empty" class="rf-signed">Signed</span>
            <span x-show="empty" class="rf-sign-sub">Draw inside the box</span>
            <button type="button" @click="clear()" class="rf-remove">Clear</button>
        </div>
    </div>
    @error($model) <p class="rf-err">{{ $message }}</p> @enderror
</div>
