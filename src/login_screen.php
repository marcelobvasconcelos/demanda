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
    <div class="w-full max-w-3xl bg-white rounded-3xl shadow-2xl border border-stone-200 overflow-hidden">
        <div class="grid md:grid-cols-2 gap-6">
            <div class="bg-stone-950 text-white px-8 py-10 flex flex-col justify-between">
                <div>
                    <div class="w-16 h-16 bg-atelier-brand text-stone-950 rounded-3xl flex items-center justify-center mb-6 shadow-lg">
                        <i data-lucide="scissors" class="w-7 h-7"></i>
                    </div>
                    <h1 class="text-4xl font-serif font-bold mb-3">Demanda</h1>
                    <p class="text-stone-200 leading-relaxed">Acesse sua conta usando Firebase Authentication. O aplicativo agora trabalha diretamente com o Firebase para remessas e autenticação.</p>
                </div>

                <div class="space-y-3 text-sm text-stone-300">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                        <span>Conexão básica com Firebase Authentication:</span>
                    </div>
                    <p><?php echo htmlspecialchars($firestoreStatusMessage ?: ($firestoreEnabled ? 'Pronto para autenticar usuários.' : 'A autenticação não está disponível.')); ?></p>
                    <p class="text-stone-400 text-xs">Antes de continuar, certifique-se que `firebase_credenciais.json` está presente na raiz do projeto.</p>
                </div>
            </div>

            <div class="p-8">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h2 class="text-2xl font-bold text-stone-900">Acesso à Demanda</h2>
                        <p class="text-sm text-stone-500">Faça login com seu e-mail e senha do Firebase Authentication.</p>
                    </div>
                </div>

                <?php if (!empty($msgSuccess)): ?>
                    <div class="mb-4 p-4 bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-2xl text-sm">
                        <?php echo htmlspecialchars($msgSuccess); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($msgError)): ?>
                    <div class="mb-4 p-4 bg-rose-50 text-rose-800 border border-rose-200 rounded-2xl text-sm">
                        <?php echo htmlspecialchars($msgError); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($loginDebugMessage)): ?>
                    <div class="mb-4 p-4 bg-slate-50 text-slate-900 border border-slate-200 rounded-2xl text-sm whitespace-pre-wrap">
                        <strong>DEBUG:</strong><br>
                        <?php echo nl2br(htmlspecialchars($loginDebugMessage)); ?>
                    </div>
                <?php endif; ?>

                <div class="grid gap-4">
                    <form method="POST" class="space-y-4 bg-stone-50 p-6 rounded-3xl border border-stone-200 shadow-sm">
                        <input type="hidden" name="action" value="login">
                        <h3 class="text-sm font-semibold uppercase tracking-[0.25em] text-stone-500 mb-2">Entrar</h3>
                        <label class="block text-sm text-stone-700">E-mail</label>
                        <input type="email" name="email" required placeholder="seu@email.com" class="w-full rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm outline-none focus:border-stone-900">
                        <label class="block text-sm text-stone-700">Senha</label>
                        <input type="password" name="senha" required placeholder="••••••••" class="w-full rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm outline-none focus:border-stone-900">
                        <button type="submit" class="w-full bg-stone-950 text-white rounded-2xl py-3 text-sm font-bold hover:bg-stone-800 transition">Entrar</button>
                    </form>
                </div>

                <div class="mt-8 text-center text-xs text-stone-400">
                    <p>Se não tiver conta, crie-a diretamente no Firebase Authentication.</p>
                </div>

                <div class="mt-6 text-center">
                    <a href="list_firebase_users.php" class="text-sm font-semibold text-stone-900 hover:text-stone-700 hover:underline">Ver usuários do Firebase Authentication</a>
                </div>
            </div>
        </div>
    </div>

    <script>lucide.createIcons();</script>
</body>
</html>
