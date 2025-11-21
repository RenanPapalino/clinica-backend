<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Cliente;
use App\Models\Servico;
use App\Models\Fatura;
use App\Models\FaturaItem;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Criar usuário admin
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@medintelligence.com',
            'password' => Hash::make('password'),
        ]);

        echo "✅ Usuário criado: admin@medintelligence.com / password\n";

        // Criar clientes de teste
        $clientes = [
            [
                'cnpj' => '12.345.678/0001-99',
                'razao_social' => 'Empresa ABC Ltda',
                'nome_fantasia' => 'ABC Corp',
                'email' => 'contato@abc.com',
                'telefone' => '(11) 98765-4321',
                'status' => 'ativo',
            ],
            [
                'cnpj' => '98.765.432/0001-10',
                'razao_social' => 'XYZ Indústria SA',
                'nome_fantasia' => 'XYZ',
                'email' => 'contato@xyz.com',
                'telefone' => '(11) 91234-5678',
                'status' => 'ativo',
            ],
            [
                'cnpj' => '11.222.333/0001-44',
                'razao_social' => 'Tech Solutions Ltda',
                'nome_fantasia' => 'Tech Solutions',
                'email' => 'contato@techsolutions.com',
                'telefone' => '(11) 99999-8888',
                'status' => 'ativo',
            ],
        ];

        foreach ($clientes as $clienteData) {
            Cliente::create($clienteData);
        }

        echo "✅ " . count($clientes) . " clientes criados\n";

        // Criar serviços
        $servicos = [
            [
                'codigo' => 'EXA-001',
                'descricao' => 'Exame Admissional',
                'valor_unitario' => 150.00,
                'unidade' => 'UN',
                'status' => 'ativo',
            ],
            [
                'codigo' => 'EXA-002',
                'descricao' => 'Exame Periódico',
                'valor_unitario' => 120.00,
                'unidade' => 'UN',
                'status' => 'ativo',
            ],
            [
                'codigo' => 'EXA-003',
                'descricao' => 'Audiometria',
                'valor_unitario' => 80.00,
                'unidade' => 'UN',
                'status' => 'ativo',
            ],
            [
                'codigo' => 'EXA-004',
                'descricao' => 'Espirometria',
                'valor_unitario' => 90.00,
                'unidade' => 'UN',
                'status' => 'ativo',
            ],
            [
                'codigo' => 'EXA-005',
                'descricao' => 'Acuidade Visual',
                'valor_unitario' => 50.00,
                'unidade' => 'UN',
                'status' => 'ativo',
            ],
        ];

        foreach ($servicos as $servicoData) {
            Servico::create($servicoData);
        }

        echo "✅ " . count($servicos) . " serviços criados\n";

        // Criar algumas faturas de exemplo
        $cliente1 = Cliente::where('cnpj', '12.345.678/0001-99')->first();
        $cliente2 = Cliente::where('cnpj', '98.765.432/0001-10')->first();

        $fatura1 = Fatura::create([
            'numero_fatura' => 'FAT-202411-000001',
            'cliente_id' => $cliente1->id,
            'data_emissao' => now(),
            'data_vencimento' => now()->addDays(30),
            'periodo_referencia' => '2024-11',
            'valor_total' => 450.00,
            'status' => 'pendente',
        ]);

        $servico1 = Servico::where('codigo', 'EXA-001')->first();
        $servico2 = Servico::where('codigo', 'EXA-003')->first();

        FaturaItem::create([
            'fatura_id' => $fatura1->id,
            'servico_id' => $servico1->id,
            'descricao' => $servico1->descricao,
            'quantidade' => 2,
            'valor_unitario' => $servico1->valor_unitario,
            'valor_total' => $servico1->valor_unitario * 2,
        ]);

        FaturaItem::create([
            'fatura_id' => $fatura1->id,
            'servico_id' => $servico2->id,
            'descricao' => $servico2->descricao,
            'quantidade' => 1,
            'valor_unitario' => $servico2->valor_unitario,
            'valor_total' => $servico2->valor_unitario,
        ]);

        $fatura2 = Fatura::create([
            'numero_fatura' => 'FAT-202411-000002',
            'cliente_id' => $cliente2->id,
            'data_emissao' => now()->subDays(45),
            'data_vencimento' => now()->subDays(15), // Vencida
            'periodo_referencia' => '2024-10',
            'valor_total' => 300.00,
            'status' => 'vencida',
        ]);

        $servico3 = Servico::where('codigo', 'EXA-002')->first();

        FaturaItem::create([
            'fatura_id' => $fatura2->id,
            'servico_id' => $servico3->id,
            'descricao' => $servico3->descricao,
            'quantidade' => 2,
            'valor_unitario' => $servico3->valor_unitario,
            'valor_total' => $servico3->valor_unitario * 2,
        ]);

        echo "✅ 2 faturas de exemplo criadas\n";
        echo "\n";
        echo "🎉 Banco de dados populado com sucesso!\n";
        echo "\n";
        echo "📝 Credenciais de acesso:\n";
        echo "   Email: admin@medintelligence.com\n";
        echo "   Senha: password\n";
    }
}
