# Configuração do MySQL no Render

## Problema Atual

O Render está rodando apenas o container PHP (web), mas **NÃO está rodando o container MySQL (db)** porque:

1. O `render.yaml` usa `runtime: docker` que roda apenas UM Dockerfile
2. O `docker-compose.yml` NÃO é executado automaticamente no Render
3. O módulo Ateliê precisa de MySQL para funcionar

## Solução: Adicionar MySQL como serviço separado no Render

### Opção 1: Usar MySQL do Render (Recomendado)

1. **No Dashboard do Render**, crie um novo serviço:
   - Clique em "New +" → "PostgreSQL" ou "MySQL" (se disponível no seu plano)
   - Ou use um banco externo gratuito

2. **Configure as variáveis de ambiente no Render**:
   - Vá em: `demanda` → Settings → Environment
   - Adicione:
     ```
     DB_HOST=<hostname-do-mysql-render>
     DB_PORT=3306
     DB_DATABASE=costureira_db
     DB_USERNAME=<usuario>
     DB_PASSWORD=<senha>
     ```

3. **Execute o script SQL de inicialização**:
   - Conecte no MySQL do Render
   - Execute o arquivo `docker/mysql/init.sql`

### Opção 2: Usar banco externo gratuito

Serviços gratuitos de MySQL:
- **Railway** (https://railway.app) - 500MB grátis
- **PlanetScale** (https://planetscale.com) - 5GB grátis
- **Aiven** (https://aiven.io) - Plano free tier

Depois de criar, adicione as credenciais nas variáveis de ambiente do Render.

### Opção 3: Modificar para usar docker-compose no Render

Altere o `render.yaml` para:

```yaml
services:
  - type: web
    name: demanda
    runtime: docker
    plan: free
    dockerCommand: docker-compose up
    autoDeploy: true
```

**ATENÇÃO**: Esta opção pode não funcionar no plano free do Render.

## Como verificar se está funcionando

Após configurar, acesse:
```
https://seu-app.onrender.com/test_mysql.php
```

Este script testa a conexão e mostra qual configuração está funcionando.

## Status Atual

- ✅ Firebase/Firestore: Funcionando
- ✅ Sistema de Remessas: Funcionando
- ❌ Módulo Ateliê: Precisa de MySQL configurado
