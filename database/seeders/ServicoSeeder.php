<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\Servico;

class ServicoSeeder extends Seeder
{
    public function run(): void
    {
        $servicos = [
            ['codigo' => 'EXAM-001', 'descricao' => 'Exame Admissional', 'valor_unitario' => 150.00, 'categoria' => 'exame', 'status' => 'ativo'],
            ['codigo' => 'EXAM-002', 'descricao' => 'Exame Periódico', 'valor_unitario' => 120.00, 'categoria' => 'exame', 'status' => 'ativo'],
            ['codigo' => 'EXAM-003', 'descricao' => 'Exame Demissional', 'valor_unitario' => 100.00, 'categoria' => 'exame', 'status' => 'ativo'],
            ['codigo' => 'EXAM-004', 'descricao' => 'Audiometria', 'valor_unitario' => 80.00, 'categoria' => 'exame', 'status' => 'ativo'],
            ['codigo' => 'EXAM-005', 'descricao' => 'Espirometria', 'valor_unitario' => 90.00, 'categoria' => 'exame', 'status' => 'ativo'],
            ['codigo' => 'CONS-001', 'descricao' => 'Consulta Medicina do Trabalho', 'valor_unitario' => 200.00, 'categoria' => 'consulta', 'status' => 'ativo'],
        ];
        foreach ($servicos as $servico) {
            Servico::create($servico);
        }
        $this->command->info('✅ ' . count($servicos) . ' serviços criados!');
    }
}
