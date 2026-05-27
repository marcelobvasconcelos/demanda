<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Demanda</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>body{background-color:#FAF6F0;}</style>
</head>
<body class="min-h-screen flex items-center justify-center px-4 py-10 font-sans text-stone-900">
    <div class="w-full max-w-3xl bg-white rounded-[2.5rem] shadow-2xl border border-stone-200 overflow-hidden">
        <div class="grid md:grid-cols-2 gap-0">
            <!-- Lado Esquerdo (Informações) -->
            <div class="bg-slate-900 text-white px-10 py-12 flex flex-col justify-between relative overflow-hidden">
                <i data-lucide="scissors" class="absolute -right-8 -bottom-8 w-48 h-48 opacity-10 rotate-12"></i>
                <div>
                    <div class="w-16 h-16 bg-indigo-600 text-white rounded-2xl flex items-center justify-center mb-8 shadow-xl shadow-indigo-500/20">
                        <i data-lucide="scissors" class="w-8 h-8"></i>
                    </div>
                    <h1 class="text-4xl font-serif font-bold mb-4 tracking-tight">Demanda</h1>
                    <p class="text-slate-300 leading-relaxed text-sm">Gestão inteligente de costura e finanças integrada diretamente com o seu Firebase. Acesse para gerenciar seus lotes e faturamento.</p>
                </div>

                <div class="space-y-4 text-xs text-slate-400 mt-10">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        <span class="text-emerald-400 font-bold uppercase tracking-widest">Firebase Online</span>
                    </div>
                    <p>Status: <?php echo htmlspecialchars($firestoreEnabled ? 'Pronto para autenticar' : 'Offline'); ?></p>
                </div>
            </div>

            <!-- Lado Direito (Formulário) -->
            <div class="p-10 md:p-12 bg-white">
                <div class="mb-10">
                    <h2 class="text-3xl font-black text-slate-800 tracking-tighter">Entrar</h2>
                    <p class="text-slate-400 text-sm mt-1">Bem-vindo(a) de volta!</p>
                </div>

                <?php if (!empty($msgSuccess)): ?>
                    <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 border border-emerald-100 rounded-2xl text-sm font-bold flex items-center gap-2">
                        <i data-lucide="check-circle" class="w-4 h-4"></i> <?php echo htmlspecialchars($msgSuccess); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($msgError)): ?>
                    <div class="mb-6 p-4 bg-rose-50 text-rose-700 border border-rose-100 rounded-2xl text-sm font-bold flex items-center gap-2">
                        <i data-lucide="alert-circle" class="w-4 h-4"></i> <?php echo htmlspecialchars($msgError); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-5">
                    <input type="hidden" name="action" value="login">
                    
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black uppercase text-slate-400 ml-1">Seu E-mail</label>
                        <div class="relative">
                            <i data-lucide="mail" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-300"></i>
                            <input type="email" name="email" required placeholder="ex: seu@email.com" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl p-4 pl-12 font-bold focus:border-indigo-500 focus:bg-white outline-none transition-all">
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black uppercase text-slate-400 ml-1">Sua Senha</label>
                        <div class="relative">
                            <i data-lucide="lock" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-300"></i>
                            <input type="password" name="password" required placeholder="••••••••" class="w-full bg-slate-50 border-2 border-slate-50 rounded-2xl p-4 pl-12 font-bold focus:border-indigo-500 focus:bg-white outline-none transition-all">
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="button" onclick="document.getElementById('forgotForm').submit()" class="text-[11px] font-black text-indigo-600 hover:text-indigo-800 uppercase tracking-wider">Esqueci minha senha</button>
                    </div>

                    <button type="submit" class="w-full bg-slate-900 text-white font-black py-5 rounded-[1.5rem] shadow-xl shadow-slate-200 hover:bg-indigo-600 transition-all active:scale-95 mt-4">Acessar Sistema</button>
                </form>

                <!-- Formulário Oculto para Recuperação -->
                <form id="forgotForm" method="POST" class="hidden">
                    <input type="hidden" name="action" value="forgot_password">
                    <input type="hidden" name="email" id="forgotEmail">
                </form>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        // Sincroniza e-mail para recuperação
        document.querySelector('input[name="email"]').addEventListener('input', function(e) {
            document.getElementById('forgotEmail').value = e.target.value;
        });
    </script>
</body>
</html>
