<!-- Cabeçalho de Ação -->
<div class="flex justify-between items-center mb-8">
    <div class="flex gap-2">
        <a href="?view=pedidos" class="px-4 py-2 rounded-xl font-bold text-sm <?= empty($filtros) ? 'bg-purple-600 text-white' : 'bg-white text-slate-500' ?>">Todos</a>
        <a href="?view=pedidos&status_entrega=Pendente" class="px-4 py-2 rounded-xl font-bold text-sm <?= ($_GET['status_entrega'] ?? '') === 'Pendente' ? 'bg-amber-500 text-white' : 'bg-white text-slate-500' ?>">Pendentes</a>
        <a href="?view=pedidos&status_pagamento=Pendente" class="px-4 py-2 rounded-xl font-bold text-sm <?= ($_GET['status_pagamento'] ?? '') === 'Pendente' ? 'bg-rose-500 text-white' : 'bg-white text-slate-500' ?>">Não Pagos</a>
    </div>
    <button onclick="toggleModal('modalNovoPedido')" class="bg-slate-900 text-white px-6 py-4 rounded-2xl font-black flex items-center gap-3 shadow-xl hover:bg-purple-600 transition-all">
        <i data-lucide="plus-circle" class="w-5 h-5"></i> Novo Pedido
    </button>
</div>

<!-- Lista de Pedidos -->
<div class="space-y-4">
    <?php if (empty($pedidos)): ?>
        <div class="bg-white border-2 border-dashed border-slate-200 rounded-3xl p-20 text-center">
            <i data-lucide="inbox" class="w-16 h-16 mx-auto mb-4 text-slate-300"></i>
            <h3 class="text-xl font-black text-slate-800 mb-2">Nenhum pedido encontrado</h3>
            <p class="text-sm text-slate-500">Comece criando um novo pedido</p>
        </div>
    <?php else: ?>
        <?php foreach ($pedidos as $p): 
            $saldoDevedor = floatval($p['saldo_devedor']);
            $isPago = $saldoDevedor <= 0.01;
            $statusEntregaBadge = match($p['status_entrega']) {
                'Pendente' => 'bg-amber-100 text-amber-700 border-amber-200',
                'Em Produção' => 'bg-blue-100 text-blue-700 border-blue-200',
                'Entregue' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                default => 'bg-slate-100 text-slate-700 border-slate-200'
            };
            $statusPagBadge = $isPago ? 'bg-emerald-100 text-emerald-700 border-emerald-200' : 'bg-rose-100 text-rose-700 border-rose-200';
        ?>
        <div class="bg-white rounded-3xl p-6 shadow-lg border border-slate-100 hover:border-purple-200 transition-all">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div class="flex-grow">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="w-12 h-12 bg-purple-100 rounded-2xl flex items-center justify-center text-purple-600">
                            <i data-lucide="user" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-black text-slate-800"><?= htmlspecialchars($p['cliente_nome']) ?></h3>
                            <p class="text-sm text-slate-500 font-bold"><?= htmlspecialchars($p['cliente_telefone'] ?? 'Sem telefone') ?></p>
                            <p class="text-xs text-slate-400 mt-1">Pedido #<?= $p['id'] ?> • <?= date('d/m/Y', strtotime($p['data_pedido'])) ?></p>
                        </div>
                    </div>
                    
                    <?php if (!empty($p['observacoes'])): ?>
                        <div class="bg-slate-50 rounded-2xl p-4 mb-4">
                            <p class="text-sm text-slate-600"><?= nl2br(htmlspecialchars($p['observacoes'])) ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="flex flex-wrap gap-2">
                        <span class="px-3 py-1 rounded-lg text-xs font-black border <?= $statusEntregaBadge ?>">
                            <?= $p['status_entrega'] ?>
                        </span>
                        <span class="px-3 py-1 rounded-lg text-xs font-black border <?= $statusPagBadge ?>">
                            <?= $isPago ? 'PAGO' : 'PENDENTE: ' . formatReal($saldoDevedor) ?>
                        </span>
                    </div>
                </div>

                <div class="flex flex-col gap-4 lg:items-end">
                    <div class="text-right">
                        <span class="text-sm font-bold text-slate-500 block">Total do Pedido</span>
                        <span class="text-3xl font-black text-slate-900"><?= formatReal($p['valor_total']) ?></span>
                        <span class="text-xs font-bold text-emerald-600 block mt-1">Pago: <?= formatReal($p['valor_pago']) ?></span>
                    </div>

                    <div class="flex gap-2">
                        <button onclick="openAtualizarPedido(<?= htmlspecialchars(json_encode($p)) ?>)" class="px-4 py-2 bg-indigo-600 text-white rounded-xl font-bold text-sm hover:bg-indigo-700 transition-all">
                            <i data-lucide="edit" class="w-4 h-4 inline mr-1"></i> Atualizar
                        </button>
                        <form method="POST" onsubmit="return confirm('Excluir este pedido?')" class="inline">
                            <input type="hidden" name="action" value="excluir_pedido">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button type="submit" class="px-4 py-2 bg-rose-500 text-white rounded-xl font-bold text-sm hover:bg-rose-600 transition-all">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal Novo Pedido -->
<div id="modalNovoPedido" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm" onclick="toggleModal('modalNovoPedido')"></div>
        <div class="bg-white rounded-3xl shadow-2xl z-10 w-full max-w-3xl p-10 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-8">
                <h3 class="font-serif text-3xl font-black text-slate-800">Novo Pedido</h3>
                <button onclick="toggleModal('modalNovoPedido')" class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-rose-500">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <form method="POST" class="space-y-6">
                <input type="hidden" name="action" value="salvar_pedido">

                <div class="space-y-2">
                    <label class="text-xs font-black uppercase text-slate-400 ml-2">Cliente</label>
                    <select name="cliente_id" required class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl p-4 font-bold focus:border-purple-500 outline-none">
                        <option value="">Selecione um cliente</option>
                        <?php foreach ($clientes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="bg-slate-50 rounded-2xl p-6 space-y-4">
                    <div class="flex justify-between items-center">
                        <h4 class="font-black text-slate-800">Serviços</h4>
                        <button type="button" onclick="adicionarItemPedido()" class="px-4 py-2 bg-purple-600 text-white rounded-xl font-bold text-sm">
                            <i data-lucide="plus" class="w-4 h-4 inline"></i> Adicionar
                        </button>
                    </div>
                    <div id="itensPedido" class="space-y-3"></div>
                    <div class="text-right pt-4 border-t border-slate-200">
                        <span class="text-sm font-bold text-slate-500">Total: </span>
                        <span id="totalPedido" class="text-2xl font-black text-slate-900">R$ 0,00</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-xs font-black uppercase text-slate-400 ml-2">Valor Pago (Entrada)</label>
                        <input type="number" step="0.01" name="valor_pago" value="0" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl p-4 font-bold focus:border-emerald-500 outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-black uppercase text-slate-400 ml-2">Status de Entrega</label>
                        <select name="status_entrega" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl p-4 font-bold focus:border-purple-500 outline-none">
                            <option value="Pendente">Pendente</option>
                            <option value="Em Produção">Em Produção</option>
                            <option value="Entregue">Entregue</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-xs font-black uppercase text-slate-400 ml-2">Data do Pedido</label>
                    <input type="date" name="data_pedido" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl p-4 font-bold focus:border-purple-500 outline-none">
                </div>

                <div class="space-y-2">
                    <label class="text-xs font-black uppercase text-slate-400 ml-2">Observações</label>
                    <textarea name="observacoes" rows="3" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl p-4 font-bold focus:border-purple-500 outline-none resize-none"></textarea>
                </div>

                <button type="submit" class="w-full bg-slate-900 text-white font-black py-5 rounded-2xl shadow-xl hover:bg-purple-600 transition-all">
                    Salvar Pedido
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Modal Atualizar Pedido -->
<div id="modalAtualizarPedido" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm" onclick="toggleModal('modalAtualizarPedido')"></div>
        <div class="bg-white rounded-3xl shadow-2xl z-10 w-full max-w-md p-10">
            <h3 class="text-2xl font-black text-slate-800 mb-8">Atualizar Pedido</h3>
            <form method="POST" class="space-y-6">
                <input type="hidden" name="action" value="atualizar_pedido">
                <input type="hidden" name="id" id="updatePedidoId">

                <div class="space-y-2">
                    <label class="text-xs font-black uppercase text-slate-400 ml-2">Valor Pago</label>
                    <input type="number" step="0.01" name="valor_pago" id="updateValorPago" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl p-4 font-bold focus:border-emerald-500 outline-none">
                </div>

                <div class="space-y-2">
                    <label class="text-xs font-black uppercase text-slate-400 ml-2">Status de Entrega</label>
                    <select name="status_entrega" id="updateStatusEntrega" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl p-4 font-bold focus:border-purple-500 outline-none">
                        <option value="Pendente">Pendente</option>
                        <option value="Em Produção">Em Produção</option>
                        <option value="Entregue">Entregue</option>
                    </select>
                </div>

                <button type="submit" class="w-full bg-emerald-600 text-white font-black py-5 rounded-2xl shadow-xl hover:bg-emerald-700 transition-all">
                    Salvar Alterações
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const catalogoServicos = <?= json_encode($catalogo) ?>;
let contadorItens = 0;

function adicionarItemPedido() {
    const container = document.getElementById('itensPedido');
    const item = document.createElement('div');
    item.className = 'flex gap-2 items-start';
    item.innerHTML = `
        <select name="servico_id[]" onchange="atualizarPreco(this)" class="flex-grow bg-white border border-slate-200 rounded-xl p-3 text-sm font-bold">
            <option value="">Selecione um serviço</option>
            ${catalogoServicos.map(s => `<option value="${s.id}" data-preco="${s.preco_base}">${s.nome_servico} - R$ ${parseFloat(s.preco_base).toFixed(2)}</option>`).join('')}
        </select>
        <input type="number" name="quantidade[]" value="1" min="1" onchange="calcularTotal()" class="w-20 bg-white border border-slate-200 rounded-xl p-3 text-sm font-bold text-center">
        <input type="number" step="0.01" name="preco_aplicado[]" onchange="calcularTotal()" class="w-28 bg-white border border-slate-200 rounded-xl p-3 text-sm font-bold">
        <button type="button" onclick="this.parentElement.remove(); calcularTotal()" class="w-10 h-10 bg-rose-100 text-rose-600 rounded-xl hover:bg-rose-200">
            <i data-lucide="x" class="w-4 h-4 mx-auto"></i>
        </button>
    `;
    container.appendChild(item);
    lucide.createIcons();
    contadorItens++;
}

function atualizarPreco(select) {
    const preco = select.options[select.selectedIndex]?.dataset.preco || 0;
    const precoInput = select.parentElement.querySelector('input[name="preco_aplicado[]"]');
    precoInput.value = parseFloat(preco).toFixed(2);
    calcularTotal();
}

function calcularTotal() {
    let total = 0;
    document.querySelectorAll('#itensPedido > div').forEach(item => {
        const qtd = parseFloat(item.querySelector('input[name="quantidade[]"]').value) || 0;
        const preco = parseFloat(item.querySelector('input[name="preco_aplicado[]"]').value) || 0;
        total += qtd * preco;
    });
    document.getElementById('totalPedido').textContent = 'R$ ' + total.toFixed(2).replace('.', ',');
}

function openAtualizarPedido(pedido) {
    document.getElementById('updatePedidoId').value = pedido.id;
    document.getElementById('updateValorPago').value = pedido.valor_pago;
    document.getElementById('updateStatusEntrega').value = pedido.status_entrega;
    toggleModal('modalAtualizarPedido');
}

// Adicionar primeiro item ao abrir modal
document.querySelector('[onclick*="modalNovoPedido"]')?.addEventListener('click', () => {
    setTimeout(() => {
        if (document.getElementById('itensPedido').children.length === 0) {
            adicionarItemPedido();
        }
    }, 100);
});
</script>
