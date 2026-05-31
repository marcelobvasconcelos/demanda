# 🚀 Guia Completo: Configurar MySQL para o Módulo Ateliê no Render

## ❌ Problema Atual

O Render **não suporta docker-compose** no plano free, então o container MySQL não está rodando.

## ✅ Solução: Usar MySQL Externo Gratuito

---

## 🎯 OPÇÃO 1: Railway (RECOMENDADO - Mais Fácil)

### Passo 1: Criar conta no Railway
1. Acesse: https://railway.app
2. Clique em **"Login"** e use sua conta GitHub
3. Você ganha **$5 de crédito grátis por mês** (suficiente para MySQL pequeno)

### Passo 2: Criar banco MySQL
1. No dashboard, clique em **"New Project"**
2. Selecione **"Provision MySQL"**
3. Aguarde a criação (leva ~30 segundos)

### Passo 3: Copiar credenciais
Clique no serviço MySQL criado e copie:
- **MYSQLHOST** (exemplo: `containers-us-west-123.railway.app`)
- **MYSQLPORT** (exemplo: `6789`)
- **MYSQLDATABASE** (geralmente `railway`)
- **MYSQLUSER** (geralmente `root`)
- **MYSQLPASSWORD** (senha gerada automaticamente)

### Passo 4: Configurar no Render
1. Vá no dashboard do Render
2. Selecione o serviço **"demanda"**
3. Vá em **"Environment"** (menu lateral)
4. Adicione estas variáveis:

```
DB_HOST=<MYSQLHOST do Railway>
DB_PORT=<MYSQLPORT do Railway>
DB_DATABASE=railway
DB_USERNAME=<MYSQLUSER do Railway>
DB_PASSWORD=<MYSQLPASSWORD do Railway>
```

5. Clique em **"Save Changes"**
6. O Render vai fazer redeploy automaticamente

### Passo 5: Importar o Schema SQL

**Opção A - Via Railway Dashboard (Mais Fácil):**
1. No Railway, clique no serviço MySQL
2. Vá na aba **"Data"**
3. Clique em **"Query"**
4. Copie e cole o conteúdo do arquivo `docker/mysql/init.sql`
5. Execute

**Opção B - Via Railway CLI:**
```bash
# Instalar Railway CLI
npm i -g @railway/cli

# Fazer login
railway login

# Conectar ao projeto
railway link

# Importar SQL
railway run mysql -h <MYSQLHOST> -P <MYSQLPORT> -u <MYSQLUSER> -p < docker/mysql/init.sql
```

**Opção C - Via MySQL Workbench/DBeaver:**
1. Baixe MySQL Workbench ou DBeaver
2. Crie nova conexão com as credenciais do Railway
3. Abra o arquivo `docker/mysql/init.sql`
4. Execute

### Passo 6: Testar
Acesse: `https://seu-app.onrender.com/test_mysql.php`

Deve aparecer: ✅ CONEXÃO OK!

---

## 🎯 OPÇÃO 2: PlanetScale (5GB Grátis)

### Passo 1: Criar conta
1. Acesse: https://planetscale.com
2. Faça login com GitHub
3. Plano free: **5GB de armazenamento**

### Passo 2: Criar banco
1. Clique em **"Create database"**
2. Nome: `demanda`
3. Região: escolha a mais próxima
4. Clique em **"Create database"**

### Passo 3: Obter credenciais
1. Vá em **"Connect"**
2. Selecione **"PHP PDO"**
3. Copie as credenciais

### Passo 4: Configurar no Render
Adicione as variáveis de ambiente no Render (igual Railway)

### Passo 5: Importar Schema
PlanetScale não suporta `FOREIGN KEY` diretamente. Use este SQL modificado:

```sql
-- Copie o conteúdo de init.sql mas remova as linhas:
-- CONSTRAINT `fk_...` FOREIGN KEY ...
-- ON DELETE CASCADE
```

---

## 🎯 OPÇÃO 3: Aiven (Free Tier)

### Passo 1: Criar conta
1. Acesse: https://aiven.io
2. Crie conta gratuita
3. Free tier: **1 serviço MySQL grátis**

### Passo 2: Criar serviço MySQL
1. Clique em **"Create service"**
2. Selecione **"MySQL"**
3. Escolha plano **"Hobbyist"** (free)
4. Região: escolha a mais próxima
5. Clique em **"Create service"**

### Passo 3: Aguardar inicialização
Leva ~5 minutos para o serviço ficar pronto

### Passo 4: Copiar credenciais
Na página do serviço, copie:
- Host
- Port
- User
- Password
- Database

### Passo 5: Configurar no Render
Adicione as variáveis de ambiente (igual Railway)

### Passo 6: Importar Schema
Use o console SQL do Aiven ou conecte via cliente MySQL

---

## 📊 Comparação das Opções

| Serviço | Armazenamento | Facilidade | Velocidade |
|---------|---------------|------------|------------|
| **Railway** | ~500MB | ⭐⭐⭐⭐⭐ | Rápido |
| **PlanetScale** | 5GB | ⭐⭐⭐⭐ | Muito Rápido |
| **Aiven** | 1GB | ⭐⭐⭐ | Médio |

## 🎯 Recomendação

Use **Railway** - é o mais fácil de configurar e integra perfeitamente com GitHub.

---

## ✅ Checklist Final

- [ ] Criar conta no serviço escolhido
- [ ] Criar banco MySQL
- [ ] Copiar credenciais
- [ ] Adicionar variáveis de ambiente no Render
- [ ] Importar schema SQL
- [ ] Testar em `/test_mysql.php`
- [ ] Acessar `/atelie_sob_medida.php`

---

## 🆘 Precisa de Ajuda?

Se tiver dúvidas em algum passo, me avise qual opção você escolheu e em qual passo está!
