<div class="flex justify-between items-center mb-8">
    <h2 class="text-2xl font-black text-slate-800">Gerenciar Clientes</h2>
    <button onclick="toggleModal('modalNovoCliente')" class="bg-slate-900 text-white px-6 py-4 rounded-2xl font-black flex items-center gap-3 shadow-xl hover:bg-purple-600 transition-all">
        <i data-lucide="user-plus" class="w-5 h-5"></i> Novo Cliente
    </button>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php if (empty($clientes)): ?>
        <div class="col-span-full bg-white border-2 border-dashed border-slate-200 rounded-3xl p-20 text-center">
            <i data-lucide="users" class="w-16 h-16 mx-auto mb-4 text-slate-300"></i>
            <h3 class="text-xl font-black text-slate-800 mb-2">Nenhum cliente cadastrado</h3>
            <p class="text-sm text-slate-500">Adicione seu primeiro cliente</p>
        </div>
    <?php else: ?>
        <?php foreach ($clientes as $c): 
            $medidas = json_decode($c['medidas_json'] ?? '{}', true);
        ?>
        <div class="bg-white rounded-3xl p-6 shadow-lg border border-slate-100 hover:border-purple-200 transition-all">
            <div class="flex items-start justify-between mb-4">
                <div class="w-14 h-14 bg-purple-100 rounded-2xl flex items-center justify-center text-purple-600">
                    <i data-lucide="user" class="w-7 h-7"></i>
                </div>
                <div class="flex gap-2">
                    <button onclick="openEditarCliente(<?= htmlspecialchars(json_encode($c)) ?>)" class="w-9 h-9 bg-slate-50 text-slate-400 rounded-xl hover:bg-indigo-50 hover:text-indigo-600 transition-all flex items-center justify-center">
                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                    </button>
                    <form method="POST" onsubmit="return confirm('Excluir este cliente?')" class="inline">
                        <input type="hidden" name="action" value="excluir_cliente">
                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                        <button type="submit" class="w-9 h-9 bg-slate-50 text-slate-400 rounded-xl hover:bg-rose-50 hover:text-rose-500 transition-all flex items-center justify-center">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </form>
                </div>
            </div>

            <h3 class="text-xl font-black text-slate-800 mb-2"><?= htmlspecialchars($c['nome']) ?></h3>
            <p class="text-sm text-slate-500 font-bold mb-4"><?= htmlspecialchars($c['telefone'] ?? 'Sem telefone') ?></p>

            <?php if (!empty($medidas) && array_filter($medidas)): ?>
                <div class="bg-slate-50 rounded-2xl p-4 space-y-2">
                    <h4 class="text-xs font-black uppercase text-slate-400 mb-3">Medidas</h4>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <?php if (!empty($medidas['busto'])): ?>
                            <div><span class="text-slate-500">Busto:</span> <span class="font-bold"><?= $medidas['busto'] ?>cm</span></div>
                        <?php endif; ?>
                        <?php if (!empty($medidas['cintura'])): ?>
                            <div><span class="text-slate-500">Cintura:</span> <span class="font-bold"><?= $medidas['cintura'] ?>cm</span></div>
                        <?php endif; ?>
                        <?php if (!empty($medidas['quadril'])): ?>
                            <div><span class="text-slate-500">Quadril:</span> <span class="font-bold"><?= $medidas['quadril'] ?>cm</span></div>
                        <?php endif; ?>
                        <?php if (!empty($medidas['comprimento'])): ?>
                            <div><span class="text-slate-500">Comprimento:</span> <span class="font-bold"><?= $medidas['comprimento'] ?>cm</span></div>
                        <?php endif; ?>
                        <?php if (!empty($medidas['ombro'])): ?>
                            <div><span class="text-slate-500">Ombro:</span> <span class="font-bold"><?= $medidas['ombro'] ?>cm</span></div>
                        <?php endif; ?>
                        <?php if (!empty($medidas['manga'])): ?>
                            <div><span class="text-slate-500">Manga:</span> <span class="font-bold"><?= $medidas['manga'] ?>cm</span></div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($medidas['observacoes'])): ?>
                        <div class="pt-2 border-t border-slate-200 mt-2">
                            <p class="text-xs text-slate-600"><?= nl2br(htmlspecialchars($medidas['observacoes'])) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-3 text-center">
                    <p class="text-xs font-bold text-amber-700">Sem medidas cadastradas</p>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal Novo/Editar Cliente -->
<div id="modalNovoCliente" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm" onclick="toggleModal('modalNovoCliente')"></div>
        <div class="bg-white rounded-3xl shadow-2xl z-10 w-full max-w-2xl p-10 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-8">
                <h3 class="font-serif text-3xl font-black text-slate-800" id="tituloModalCliente">Novo Cliente</h3>
                <button onclick="toggleModal('modalNovoCliente')" class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 hover:text-rose-500">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <form method="POST" class="space-y-6">
                <input type="hidden" name="action" value="salvar_cliente">
                <input type="hidden" name="id" id="clienteId">

                <div class="space-y-2">
                    <label class="text-xs font-black uppercase text-slate-400 ml-2">Nome Completo</label>
                    <input type="text" name="nome" id="clienteNome" required placeholder="Ex: Maria da Silva" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl p-4 font-bold focus:border-purple-500 outline-none">
                </div>

                <div class="space-y-2">
                    <label class="text-xs font-black uppercase text-slate-400 ml-2">Telefone</label>
                    <input type="tel" name="telefone" id="clienteTelefone" placeholder="(11) 99999-9999" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl p-4 font-bold focus:border-purple-500 outline-none">
                </div>

                <div class="bg-purple-50 rounded-2xl p-6 space-y-4">
                    <h4 class="font-black text-purple-900 flex items-center gap-2">
                        <i data-lucide="ruler" class="w-5 h-5"></i> Medidas da Costura (cm)
                    </h4>
                    
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-purple-700 ml-2">Busto</label>
                            <input type="number" step="0.01" name="busto" id="clienteBusto" placeholder="0.00" class="w-full bg-white border border-purple-200 rounded-xl p-3 font-bold focus:border-purple-500 outline-none">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-purple-700 ml-2">Cintura</label>
                            <input type="number" step="0.01" name="cintura" id="clienteCintura" placeholder="0.00" class="w-full bg-white border border-purple-200 rounded-xl p-3 font-bold focus:border-purple-500 outline-none">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-purple-700 ml-2">Quadril</label>
                            <input type="number" step="0.01" name="quadril" id="clienteQuadril" placeholder="0.00" class="w-full bg-white border border-purple-200 rounded-xl p-3 font-bold focus:border-purple-500 outline-none">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-purple-700 ml-2">Comprimento</label>
                            <input type="number" step="0.01" name="comprimento" id="clienteComprimento" placeholder="0.00" class="w-full bg-white border border-purple-200 rounded-xl p-3 font-bold focus:border-purple-500 outline-none">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-purple-700 ml-2">Ombro</label>
                            <input type="number" step="0.01" name="ombro" id="clienteOmbro" placeholder="0.00" class="w-full bg-white border border-purple-200 rounded-xl p-3 font-bold focus:border-purple-500 outline-none">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-purple-700 ml-2">Manga</label>
                            <input type="number" step="0.01" name="manga" id="clienteManga" placeholder="0.00" class="w-full bg-white border border-purple-200 rounded-xl p-3 font-bold focus:border-purple-500 outline-none">
                        </div>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-purple-700 ml-2">Observações sobre as Medidas</label>
                        <textarea name="obs_medidas" id="clienteObsMedidas" rows="2" placeholder="Ex: Cliente prefere roupas mais justas..." class="w-full bg-white border border-purple-200 rounded-xl p-3 font-bold focus:border-purple-500 outline-none resize-none"></textarea>
                    </div>
                </div>

                <button type="submit" class="w-full bg-slate-900 text-white font-black py-5 rounded-2xl shadow-xl hover:bg-purple-600 transition-all">
                    Salvar Cliente
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function openEditarCliente(cliente) {
    document.getElementById('tituloModalCliente').textContent = 'Editar Cliente';
    document.getElementById('clienteId').value = cliente.id;
    document.getElementById('clienteNome').value = cliente.nome;
    document.getElementById('clienteTelefone').value = cliente.telefone || '';
    
    const medidas = JSON.parse(cliente.medidas_json || '{}');
    document.getElementById('clienteBusto').value = medidas.busto || '';
    document.getElementById('clienteCintura').value = medidas.cintura || '';
    document.getElementById('clienteQuadril').value = medidas.quadril || '';
    document.getElementById('clienteComprimento').value = medidas.comprimento || '';
    document.getElementById('clienteOmbro').value = medidas.ombro || '';
    document.getElementById('clienteManga').value = medidas.manga || '';
    document.getElementById('clienteObsMedidas').value = medidas.observacoes || '';
    
    toggleModal('modalNovoCliente');
}

// Limpar form ao abrir para novo
document.querySelector('[onclick*="modalNovoCliente"]')?.addEventListener('click', () => {
    document.getElementById('tituloModalCliente').textContent = 'Novo Cliente';
    document.getElementById('clienteId').value = '';
    document.querySelector('#modalNovoCliente form').reset();
});
</script>
