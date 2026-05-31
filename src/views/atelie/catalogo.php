<div class="flex justify-between items-center mb-8">
    <h2 class="text-2xl font-black text-slate-800">Catálogo de Serviços</h2>
    <button onclick="toggleModal('modalNovoServico')" class="bg-slate-900 text-white px-6 py-4 rounded-2xl font-black flex items-center gap-3 shadow-xl hover:bg-purple-600 transition-all">
        <i data-lucide="plus-circle" class="w-5 h-5"></i> Novo Serviço
    </button>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
    <?php if (empty($catalogo)): ?>
        <div class="col-span-full bg-white border-2 border-dashed border-slate-200 rounded-3xl p-20 text-center">
            <i data-lucide="package" class="w-16 h-16 mx-auto mb-4 text-slate-300"></i>
            <h3 class="text-xl font-black text-slate-800 mb-2">Nenhum serviço cadastrado</h3>
            <p class="text-sm text-slate-500">Adicione serviços ao catálogo</p>
        </div>
    <?php else: ?>
        <?php foreach ($catalogo as $s): ?>
        <div class="bg-white rounded-3xl p-6 shadow-lg border border-slate-100 hover:border-purple-200 transition-all group">
            <div class="flex items-start justify-between mb-4">
                <div class="w-12 h-12 bg-indigo-100 rounded-2xl flex items-center justify-center text-indigo-600 group-hover:bg-purple-100 group-hover:text-purple-600 transition-all">
                    <i data-lucide="scissors" class="w-6 h-6"></i>
                </div>
                <div class="flex gap-2">
                    <button onclick="openEditarServico(<?= htmlspecialchars(json_encode($s)) ?>)" class="w-9 h-9 bg-slate-50 text-slate-400 rounded-xl hover:bg-indigo-50 hover:text-indigo-600 transition-all flex items-center justify-center">
                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                    </button>
                    <form method="POST" onsubmit="return confirm('Excluir este serviço?')" class="inline">
                        <input type="hidden" name="action" value="excluir_servico">
                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                        <button type="submit" class="w-9 h-9 bg-slate-50 text-slate-400 rounded-xl hover:bg-rose-50 hover:text-rose-500 transition-all flex items-center justify-center">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </form>
                </div>
            </div>

            <h3 class="text-lg font-black text-slate-800 mb-4 leading-tight"><?= htmlspecialchars($s['nome_servico']) ?></h3>

            <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-2xl p-4 text-center border border-purple-100">
                <span class="text-xs font-black uppercase text-purple-600 block mb-1">Preço Base</span>
                <span class="text-3xl font-black text-purple-900"><?= formatReal($s['preco_base']) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal Novo/Editar Serviço -->
<div id="modalNovoServico" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm" onclick="toggleModal('modalNovoServico')"></div>
        <div class="bg-white rounded-3xl shadow-2xl z-10 w-full max-w-md p-10">
            <div class="flex justify-between items-center mb-8">
                <h3 class="font-serif text-3xl font-black text-slate-800" id="tituloModalServico">Novo Serviço</h3>
                <button onclick="toggleModal('modalNovoServico')" class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-rose-500">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <form method="POST" class="space-y-6">
                <input type="hidden" name="action" value="salvar_servico">
                <input type="hidden" name="id" id="servicoId">

                <div class="space-y-2">
                    <label class="text-xs font-black uppercase text-slate-400 ml-2">Nome do Serviço</label>
                    <input type="text" name="nome_servico" id="servicoNome" required placeholder="Ex: Ajuste de Barra" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl p-4 font-bold focus:border-purple-500 outline-none">
                </div>

                <div class="space-y-2">
                    <label class="text-xs font-black uppercase text-slate-400 ml-2">Preço Base (R$)</label>
                    <input type="number" step="0.01" name="preco_base" id="servicoPreco" required placeholder="0,00" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl p-4 font-bold text-2xl focus:border-purple-500 outline-none text-center">
                </div>

                <div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-4">
                    <p class="text-xs font-bold text-indigo-700 text-center">
                        <i data-lucide="info" class="w-4 h-4 inline mr-1"></i>
                        Este preço pode ser ajustado individualmente em cada pedido
                    </p>
                </div>

                <button type="submit" class="w-full bg-slate-900 text-white font-black py-5 rounded-2xl shadow-xl hover:bg-purple-600 transition-all">
                    Salvar Serviço
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function openEditarServico(servico) {
    document.getElementById('tituloModalServico').textContent = 'Editar Serviço';
    document.getElementById('servicoId').value = servico.id;
    document.getElementById('servicoNome').value = servico.nome_servico;
    document.getElementById('servicoPreco').value = parseFloat(servico.preco_base).toFixed(2);
    toggleModal('modalNovoServico');
}

// Limpar form ao abrir para novo
document.querySelector('[onclick*="modalNovoServico"]')?.addEventListener('click', () => {
    document.getElementById('tituloModalServico').textContent = 'Novo Serviço';
    document.getElementById('servicoId').value = '';
    document.querySelector('#modalNovoServico form').reset();
});
</script>
